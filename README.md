# Splatter Innovations v1.8.6 — GoDaddy PHP Edition

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


## v1.8.5 upload/API hardening

- Bio and project images are resized/compressed in the browser before upload to avoid shared-hosting HTTP 413 limits.
- Uploads use multipart/form-data instead of base64 JSON, reducing request size.
- The PHP API remains bundled under `api/` and includes `/api/health`, `/api/meta`, and authenticated `/api/admin/system` diagnostics.
- Upload and bio/project save errors are caught and displayed instead of becoming unhandled promise errors.
- Authenticated admin requests report expired sessions clearly.

## v1.8.5 — Brain Splatter manual sync

The Admin Dashboard includes a Brain Splatter Connection card with Configure, Test Connection, and Sync Now controls. Connection credentials are stored server-side in `data/brain-splatter.json` (protected by the data directory `.htaccess`) and are never returned to the browser. Manual sync accepts a JSON feed containing a list directly or under `projects`, `items`, `data`, `results`, `published`, `recipes`, or `proposals`. The default sync mode imports only new records so local Splatter edits are preserved.


## v1.8.5 — Brain Splatter intake + manual sync split

- Brain Splatter now stores separate **Intake Endpoint** and **Sync / Read Endpoint** URLs.
- The default intake endpoint is `https://brain-splatter-ai-backend.rork.app/api/idea-capture/intake`.
- **Test Intake** performs a non-mutating reachability check; it does not create a Brain Splatter idea. HTTP 401/403/405 can still indicate that the protected/POST-only endpoint exists.
- **Test Sync** and **Sync Now** stay disabled until a read/GET endpoint is configured.
- Manual sync still imports only new Brain Splatter projects by default and preserves local edits.
- Existing v1.8.3 `apiUrl` settings are migrated automatically into the new `syncUrl` field.


## v1.8.6 — Restore original Brain Splatter portfolio connection

- Restores the original Brain Splatter backend model used before the PHP migration.
- Uses one configured Brain Splatter endpoint plus the Brain Splatter/Supabase User ID.
- Accepts the existing intake URL and automatically derives the backend origin.
- Manual **Test Connection** reads `/api/portfolio/projects?user_id=...`.
- Manual **Sync Now** pulls published Brain Splatter projects into the local Splatter JSON database.
- Existing v1.8.3-v1.8.5 Brain Splatter settings are migrated automatically where possible.
