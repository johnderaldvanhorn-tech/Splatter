const http=require('http'); const fs=require('fs'); const path=require('path'); const crypto=require('crypto'); const fetch=(...args)=>import('node-fetch').then(({default:fetch})=>fetch(...args));
const PORT=process.env.PORT||4173;
const ROOT=__dirname;
const PUBLIC=path.join(ROOT,'public');
const DATA=path.join(ROOT,'data');
const UPLOADS=path.join(PUBLIC,'uploads');
const BACKUPS=path.join(ROOT,'backups');
const PROJECTS_FILE=path.join(DATA,'projects.json');
const BIO_FILE=path.join(DATA,'bio.json');
const SETTINGS_FILE=path.join(DATA,'settings.json');
const USERS_FILE=path.join(DATA,'users.json');
const SECRET_FILE=path.join(DATA,'.session-secret');
if(!fs.existsSync(DATA))fs.mkdirSync(DATA,{recursive:true});
if(!fs.existsSync(UPLOADS))fs.mkdirSync(UPLOADS,{recursive:true});
if(!fs.existsSync(BACKUPS))fs.mkdirSync(BACKUPS,{recursive:true});
if(!fs.existsSync(path.join(UPLOADS,'bio')))fs.mkdirSync(path.join(UPLOADS,'bio'),{recursive:true});
if(!fs.existsSync(path.join(UPLOADS,'projects')))fs.mkdirSync(path.join(UPLOADS,'projects'),{recursive:true});
function stamp(){return new Date().toISOString().replace(/[:.]/g,'-')}
function readJson(f,def=null){try{return JSON.parse(fs.readFileSync(f,'utf8'))}catch(e){return def}}
function atomicWrite(f,v){if(fs.existsSync(f))fs.copyFileSync(f,path.join(BACKUPS,`${path.basename(f,'.json')}-${stamp()}.json`));const t=`${f}.${process.pid}.tmp`;fs.writeFileSync(t,JSON.stringify(v,null,2));JSON.parse(fs.readFileSync(t,'utf8'));fs.renameSync(t,f)}
function hashPassword(password,salt=crypto.randomBytes(16).toString('hex')){const hash=crypto.scryptSync(password,salt,64).toString('hex');return `${salt}:${hash}`}
function verifyPassword(password,stored){const [salt,hash]=stored.split(':');const actual=crypto.scryptSync(password,salt,64);return crypto.timingSafeEqual(actual,Buffer.from(hash,'hex'))}
if(!readJson(USERS_FILE,[]).length) atomicWrite(USERS_FILE,[{username:'admin',passwordHash:hashPassword('splatter')}]);
if(!fs.existsSync(SECRET_FILE)) fs.writeFileSync(SECRET_FILE,crypto.randomBytes(48).toString('hex'),{mode:0o600});
const SECRET=fs.readFileSync(SECRET_FILE,'utf8').trim();
function sign(value){return `${value}.${crypto.createHmac('sha256',SECRET).update(value).digest('hex')}`}
function sessionUser(req){const raw=(req.headers.cookie||'').split(';').map(s=>s.trim()).find(s=>s.startsWith('splatter_session='))?.split('=')[1];if(!raw)return null;const decoded=decodeURIComponent(raw);const i=decoded.lastIndexOf('.');if(i<0)return null;const value=decoded.slice(0,i),sig=decoded.slice(i+1),expected=crypto.createHmac('sha256',SECRET).update(value).digest('hex');if(sig.length!==expected.length||!crypto.timingSafeEqual(Buffer.from(sig),Buffer.from(expected)))return null;try{const x=JSON.parse(Buffer.from(value,'base64url').toString());if(x.exp<Date.now())return null;return x.user}catch{return null}}
function setSession(res,user){const value=Buffer.from(JSON.stringify({user,exp:Date.now()+8*3600e3})).toString('base64url');res.setHeader('Set-Cookie',`splatter_session=${encodeURIComponent(sign(value))}; HttpOnly; SameSite=Lax; Path=/; Max-Age=28800`)}
function clearSession(res){res.setHeader('Set-Cookie','splatter_session=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0')}
function send(res,status,data,type='application/json'){res.writeHead(status,{'Content-Type':type,'X-Content-Type-Options':'nosniff','Referrer-Policy':'no-referrer','Cache-Control':'no-store'});res.end(type==='application/json'?JSON.stringify(data):data)}
function body(req,limit=15*1024*1024){return new Promise((resolve,reject)=>{let s='';req.on('data',c=>{s+=c;if(s.length>limit){reject(new Error('Request too large'));req.destroy()}});req.on('end',()=>{try{resolve(s?JSON.parse(s):{})}catch{reject(new Error('Invalid JSON'))}});req.on('error',reject)})}
function requireAuth(req,res){const u=sessionUser(req);if(!u){send(res,401,{error:'Authentication required.'});return null}return u}
function mime(file){return ({'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'application/javascript; charset=utf-8','.json':'application/json','.png':'image/png','.jpg':'image/jpeg','.jpeg':'image/jpeg','.webp':'image/webp','.gif':'image/gif','.svg':'image/svg+xml'}[path.extname(file).toLowerCase()]||'application/octet-stream')}
function safeStatic(urlPath,res){let rel=decodeURIComponent(urlPath.split('?')[0]);if(rel==='/')rel='/index.html';const f=path.normalize(path.join(PUBLIC,rel));if(!f.startsWith(PUBLIC)){send(res,403,'Forbidden','text/plain');return}fs.stat(f,(e,st)=>{if(!e&&st.isFile()){res.writeHead(200,{'Content-Type':mime(f),'Cache-Control':rel.startsWith('/uploads/')?'public, max-age=3600':'no-cache'});fs.createReadStream(f).pipe(res)}else{const index=path.join(PUBLIC,'index.html');res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-cache'});fs.createReadStream(index).pipe(res)}})}
function saveDataUrl(dataUrl,kind){const m=String(dataUrl).match(/^data:(image\/(?:png|jpeg|webp|gif));base64,(.+)$/);if(!m)throw new Error('Unsupported image format.');const ext={ 'image/png':'png','image/jpeg':'jpg','image/webp':'webp','image/gif':'gif'}[m[1]];const buf=Buffer.from(m[2],'base64');if(buf.length>10*1024*1024)throw new Error('Image exceeds 10 MB.');const name=`${Date.now()}-${crypto.randomBytes(5).toString('hex')}.${ext}`;const dir=kind==='bio'?'bio':'projects';fs.writeFileSync(path.join(PUBLIC,'uploads',dir,name),buf);return `/uploads/${dir}/${name}`}

// ── Brain Splatter API integration ──────────────────────────────────────
// Fetches published projects from the Brain Splatter Cloudflare Worker.
// Falls back to local projects.json if the API is unreachable.

function getSettings(){return readJson(SETTINGS_FILE,{brainSplatterApiUrl:'',brainSplatterUserId:'',hiddenRemoteIds:[]})}

async function fetchBrainSplatterProjects(){
  const settings=getSettings();
  const apiUrl=settings.brainSplatterApiUrl;
  const userId=settings.brainSplatterUserId;
  const hiddenRemoteIds=Array.isArray(settings.hiddenRemoteIds)?settings.hiddenRemoteIds:[];
  if(!apiUrl||!userId) return null;
  try{
    const url=`${apiUrl}/api/portfolio/projects?user_id=${encodeURIComponent(userId)}`;
    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),5000);
    const resp=await fetch(url,{signal:controller.signal});
    clearTimeout(timeout);
    if(!resp.ok) return null;
    const data=await resp.json();
    if(!data.success||!Array.isArray(data.projects)) return null;
    // Map API project shape → site project shape, respecting local visibility overrides
    return data.projects.map(p=>({
      id:p.id,
      source:'brain-splatter',
      slug:p.slug||p.id,
      title:p.title||'Untitled',
      category:p.category||'hardware',
      status:p.status||'concept',
      accent:p.accent||'magenta',
      shortDescription:p.shortDescription||'',
      description:p.description||'',
      startedAt:p.startedAt||'',
      updatedAt:p.updatedAt||new Date().toISOString(),
      phase:p.phase||p.status||'concept',
      tags:Array.isArray(p.tags)?p.tags:[],
      heroImage:p.heroImage||'/assets/edge-node.webp',
      gallery:Array.isArray(p.gallery)?p.gallery:[],
      youtubeUrl:p.youtubeUrl||'',
      featured:Boolean(p.featured),
      published:!hiddenRemoteIds.includes(p.id)&&p.published!==false,
      displayOrder:Number(p.displayOrder||99)
    }));
  }catch(err){
    console.warn('[brain-splatter] API fetch failed, using local data:',err.message);
    return null;
  }
}

async function fetchBrainSplatterBio(){
  const settings=getSettings();
  const apiUrl=settings.brainSplatterApiUrl;
  const userId=settings.brainSplatterUserId;
  if(!apiUrl||!userId) return null;
  try{
    const url=`${apiUrl}/api/portfolio/bio?user_id=${encodeURIComponent(userId)}`;
    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),5000);
    const resp=await fetch(url,{signal:controller.signal});
    clearTimeout(timeout);
    if(!resp.ok) return null;
    const data=await resp.json();
    if(!data.success||!data.bio) return null;
    return data.bio;
  }catch(err){
    console.warn('[brain-splatter] Bio fetch failed, using local data:',err.message);
    return null;
  }
}

const server=http.createServer(async(req,res)=>{try{
 const url=new URL(req.url,`http://${req.headers.host||'localhost'}`); const p=url.pathname; const auth=sessionUser(req);
 if(p==='/api/projects'&&req.method==='GET'){
   // Try Brain Splatter API first, fall back to local JSON
   const remote=await fetchBrainSplatterProjects();
   if(remote&&remote.length>0){
     send(res,200,remote.sort((x,y)=>(x.displayOrder||999)-(y.displayOrder||999)));
     return;
   }
   const a=readJson(PROJECTS_FILE,[]);
   send(res,200,(auth?a:a.filter(x=>x.published!==false)).sort((x,y)=>(x.displayOrder||999)-(y.displayOrder||999)));
   return;
 }
 if(p==='/api/bio'&&req.method==='GET'){
   // Try Brain Splatter API first, fall back to local JSON
   const remoteBio=await fetchBrainSplatterBio();
   if(remoteBio){send(res,200,remoteBio);return}
   send(res,200,readJson(BIO_FILE,{}));return;
 }
 if(p==='/api/health'&&req.method==='GET'){send(res,200,{ok:true,version:'1.7.6',time:new Date().toISOString()});return}
if(p==='/api/settings'&&req.method==='GET'){
   const s=getSettings();
   send(res,200,{siteName:s.siteName,tagline:s.tagline,established:s.established,brainSplatterApiUrl:s.brainSplatterApiUrl,brainSplatterUserId:s.brainSplatterUserId?`${s.brainSplatterUserId.slice(0,8)}...`:'',brainSplatterConnected:Boolean(s.brainSplatterApiUrl&&s.brainSplatterUserId),hiddenRemoteIds:s.hiddenRemoteIds||[]});
   return;
 }
 if(p==='/api/settings'&&req.method==='PUT'){
   if(!requireAuth(req,res))return;
   const b=await body(req);
   const current=getSettings();
   const next={...current,...b};
   if(b.hiddenRemoteIds) next.hiddenRemoteIds=Array.isArray(b.hiddenRemoteIds)?b.hiddenRemoteIds:[];
   atomicWrite(SETTINGS_FILE,next);
   send(res,200,{ok:true,brainSplatterConnected:Boolean(next.brainSplatterApiUrl&&next.brainSplatterUserId)});
   return;
 }
 if(p==='/api/admin/remote-visibility'&&req.method==='PUT'){
   if(!requireAuth(req,res))return;
   const b=await body(req);
   const current=getSettings();
   const hidden=new Set(Array.isArray(current.hiddenRemoteIds)?current.hiddenRemoteIds:[]);
   if(b.visible) hidden.delete(b.id); else if(b.id) hidden.add(b.id);
   current.hiddenRemoteIds=Array.from(hidden);
   atomicWrite(SETTINGS_FILE,current);
   send(res,200,{ok:true,hiddenRemoteIds:current.hiddenRemoteIds});
   return;
 }
 if(p==='/api/auth/session'&&req.method==='GET'){send(res,200,{authenticated:Boolean(auth),user:auth||null});return}
 if(p==='/api/auth/login'&&req.method==='POST'){const b=await body(req);const u=readJson(USERS_FILE,[]).find(x=>x.username===b.username);if(!u||!verifyPassword(String(b.password||''),u.passwordHash)){send(res,401,{error:'Invalid username or password.'});return}setSession(res,{username:u.username});send(res,200,{ok:true,user:{username:u.username}});return}
 if(p==='/api/auth/logout'&&req.method==='POST'){clearSession(res);send(res,200,{ok:true});return}
 if(p==='/api/auth/change-password'&&req.method==='POST'){const u=requireAuth(req,res);if(!u)return;const b=await body(req);if(String(b.password||'').length<8){send(res,400,{error:'Password must be at least 8 characters.'});return}const users=readJson(USERS_FILE,[]),i=users.findIndex(x=>x.username===u.username);users[i].passwordHash=hashPassword(b.password);atomicWrite(USERS_FILE,users);send(res,200,{ok:true});return}
 if(p==='/api/admin/export/projects'&&req.method==='GET'){
  if(!requireAuth(req,res))return;
  const payload=JSON.stringify({
    exportVersion:1,
    exportedAt:new Date().toISOString(),
    site:'Splatter Innovations',
    projects:readJson(PROJECTS_FILE,[])
  },null,2);
  const filename=`splatter-projects-${new Date().toISOString().slice(0,10)}.json`;
  res.writeHead(200,{
    'Content-Type':'application/json; charset=utf-8',
    'Content-Disposition':`attachment; filename="${filename}"`,
    'Cache-Control':'no-store',
    'X-Content-Type-Options':'nosniff'
  });
  res.end(payload);
  return;
 }
 if(p==='/api/admin/import/projects'&&req.method==='POST'){
  if(!requireAuth(req,res))return;
  const b=await body(req);
  const incoming=Array.isArray(b)?b:(Array.isArray(b.projects)?b.projects:null);
  if(!incoming){send(res,400,{error:'Import file must contain a projects array.'});return}
  const clean=incoming.map((x,i)=>({
    id:String(x.id||`p-${String(i+1).padStart(2,'0')}`),
    slug:String(x.slug||x.title||`project-${i+1}`).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,''),
    title:String(x.title||'Untitled Project'), category:String(x.category||'hardware'), status:String(x.status||'concept'),
    accent:String(x.accent||'magenta'), shortDescription:String(x.shortDescription||''), description:String(x.description||''),
    startedAt:String(x.startedAt||new Date().toISOString().slice(0,10)), updatedAt:new Date().toISOString(), phase:String(x.phase||x.status||'concept'),
    tags:Array.isArray(x.tags)?x.tags.map(String):[], heroImage:String(x.heroImage||'/assets/edge-node.webp'), gallery:Array.isArray(x.gallery)?x.gallery.map(String):[],
    youtubeUrl:String(x.youtubeUrl||''), featured:Boolean(x.featured), published:x.published!==false, displayOrder:Number(x.displayOrder||i+1)
  }));
  atomicWrite(PROJECTS_FILE,clean);send(res,200,{ok:true,count:clean.length});return;
 }
 if(p==='/api/admin/uploads'&&req.method==='POST'){if(!requireAuth(req,res))return;const b=await body(req);send(res,201,{url:saveDataUrl(b.dataUrl,url.searchParams.get('kind'))});return}
 if(p==='/api/admin/projects'&&req.method==='POST'){if(!requireAuth(req,res))return;const b=await body(req),a=readJson(PROJECTS_FILE,[]);const num=Math.max(0,...a.map(x=>Number(String(x.id).replace(/\D/g,''))||0))+1;const title=String(b.title||'Untitled Project');const item={id:b.id||`p-${String(num).padStart(2,'0')}`,slug:String(b.slug||title).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,''),title,category:b.category||'hardware',status:b.status||'concept',accent:b.accent||({software:'cyan',systems:'yellow'}[b.category]||'magenta'),shortDescription:b.shortDescription||'',description:b.description||'',startedAt:b.startedAt||new Date().toISOString().slice(0,10),updatedAt:new Date().toISOString(),phase:b.phase||b.status||'concept',tags:Array.isArray(b.tags)?b.tags:[],heroImage:b.heroImage||'/assets/home-reference.jpeg',gallery:Array.isArray(b.gallery)?b.gallery:[],youtubeUrl:b.youtubeUrl||'',featured:Boolean(b.featured),published:b.published!==false,displayOrder:Number(b.displayOrder||a.length+1)};a.push(item);atomicWrite(PROJECTS_FILE,a);send(res,201,item);return}
 const pm=p.match(/^\/api\/admin\/projects\/([^/]+)$/);
 if(pm&&req.method==='PUT'){if(!requireAuth(req,res))return;const b=await body(req),a=readJson(PROJECTS_FILE,[]),i=a.findIndex(x=>x.id===pm[1]);if(i<0){send(res,404,{error:'Project not found.'});return}a[i]={...a[i],...b,updatedAt:new Date().toISOString()};atomicWrite(PROJECTS_FILE,a);send(res,200,a[i]);return}
 if(pm&&req.method==='DELETE'){if(!requireAuth(req,res))return;const a=readJson(PROJECTS_FILE,[]),n=a.filter(x=>x.id!==pm[1]);if(n.length===a.length){send(res,404,{error:'Project not found.'});return}atomicWrite(PROJECTS_FILE,n);send(res,200,{ok:true});return}
 if(p==='/api/admin/bio'&&req.method==='PUT'){if(!requireAuth(req,res))return;const b=await body(req),n={...readJson(BIO_FILE,{}),...b};atomicWrite(BIO_FILE,n);send(res,200,n);return}
 if(p.startsWith('/api/')){send(res,404,{error:'Not found.'});return}
 safeStatic(p,res);
 }catch(e){console.error(e);send(res,500,{error:e.message||'Server error'})}});
server.listen(PORT,'0.0.0.0',()=>{console.log(`Splatter Innovations v1.7.6 running at http://localhost:${PORT}`);console.log(`   Health check: http://localhost:${PORT}/api/health`);console.log(`   Settings API: http://localhost:${PORT}/api/settings`);});
