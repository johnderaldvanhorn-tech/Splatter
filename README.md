# Splatter Innovations v1.8.2 — GoDaddy PHP Edition

This release keeps the v1.8.0 public/admin interface and replaces the Node/Passenger backend with PHP + local JSON storage for standard cPanel hosting.

## Production layout
Deploy the contents of this repository directly to `~/public_html`.

- `index.html`, `app.js`, `styles.css` — public/admin UI
- `api/index.php` — PHP JSON API
- `data/*.json` — project, bio, and settings content
- `uploads/` — admin-uploaded images
- `backups/` — automatic JSON backups
- `.htaccess` — API routing and SPA fallback

## First login
- Username: `admin`
- Password: `splatter`

`data/users.json` is created automatically on the first API request and is intentionally excluded from Git. Change the password after deployment.

## GitHub Actions
The included `.github/workflows/deploy.yml` uses the existing GitHub Environment named `Splatter` and these environment secrets:

- `GODADDY_HOST`
- `GODADDY_USERNAME`
- `GODADDY_SSH_KEY`

The deployment backs up `public_html`, preserves `.well-known`, `cgi-bin`, `focus`, live `data/`, and live `uploads/`, replaces the old root site, validates PHP, and checks `https://splatterin.com/api/health`.

## No Node required
Production does not require Node.js, npm, Passenger, or Application Manager.


## v1.8.2 upload/API hardening

- Bio and project images are resized/compressed in the browser before upload to avoid shared-hosting HTTP 413 limits.
- Uploads use multipart/form-data instead of base64 JSON, reducing request size.
- The PHP API remains bundled under `api/` and includes `/api/health`, `/api/meta`, and authenticated `/api/admin/system` diagnostics.
- Upload and bio/project save errors are caught and displayed instead of becoming unhandled promise errors.
- Authenticated admin requests report expired sessions clearly.
