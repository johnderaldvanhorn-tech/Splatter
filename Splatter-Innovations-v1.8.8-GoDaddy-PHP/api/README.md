# Splatter Innovations PHP API — v1.8.2

The API is deployed with the site under `/api` and routed by the root `.htaccess` to `api/index.php`.

Public endpoints:
- `GET /api/health`
- `GET /api/meta`
- `GET /api/projects`
- `GET /api/bio`
- `GET /api/settings`
- `GET /api/auth/session`
- `POST /api/auth/login`
- `POST /api/auth/logout`

Authenticated admin endpoints:
- `GET /api/admin/system`
- `POST /api/admin/uploads?kind=bio|projects` (multipart field name: `file`)
- `POST /api/admin/projects`
- `PUT /api/admin/projects/:id`
- `DELETE /api/admin/projects/:id`
- `PUT /api/admin/bio`
- `GET /api/admin/export/projects`
- `POST /api/admin/import/projects`
- `POST /api/auth/change-password`

All content remains local to the hosted site in `data/`, `uploads/`, and `backups/`.
