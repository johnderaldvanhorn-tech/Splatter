# Splatter Innovations

Self-hosted portfolio and lightweight local CMS. Project records, biography data, uploaded media, and backups live inside this folder—no external database is required.

## Mac installation

1. Unzip the package.
2. Right-click `install-and-run.command` and choose **Open** the first time.
3. macOS may ask you to confirm opening the downloaded script.
4. The installer checks for Node.js, installs the required packages, starts the site, and opens Safari at `http://localhost:4173`.

Default admin credentials:

- Username: `admin`
- Password: `splatter`

Open the lock icon in the public header to sign in. Change the password after installation by using the API or editing the initialized account workflow in a future release.

## Files

- `data/projects.json` — project records
- `data/bio.json` — biography content
- `public/uploads/` — uploaded images
- `backups/` — automatic JSON backups before changes
- `logs/server.log` — server output

## Start and stop

- `open-site.command` — starts or opens the site
- `stop-site.command` — stops the server

## Notes

The site binds only to `127.0.0.1` by default, so it is accessible from the Mac itself. To host it publicly, place it behind a reverse proxy and set a persistent `SESSION_SECRET` environment variable.


## Motion update (v1.1.0)

This release adds animated page transitions, staggered project-card entrances, futuristic modal expansion from the clicked control, animated modal closing, hover scans, button response effects, and reduced-motion accessibility support.


## Version 1.2.0
- Corrected the homepage artwork so it uses a true product crop instead of displaying a screenshot inside the page.
- Rebuilt the logo asset with a transparent background.
- Added dedicated project thumbnails.
- Added **Download Database** in the admin dashboard. It exports all project records to a dated JSON file.

## Version 1.3.0
- Replaced the cropped logo with a clean, centered, transparent brain-and-lightbulb splatter mark.
- Updated the public header, footer, login window, admin dashboard, and default bio portrait to use the new logo asset.
- Added a matching transparent favicon for the browser tab.
- Removed the stray wordmark fragments and opaque background artifacts from the prior logo.


## Version 1.4.0
- Five reusable futuristic product placeholder families.
- Random ambient glitch effects (20-90s interval).
- Scan-line animations, LED pulses, subtle UI flickers.
- Project cards animate on hover.
- Modal windows animate from clicked element.
- JSON database export/import from admin.

## Version 1.5.0
- Reduced the starter portfolio to five varied futuristic product placeholders.
- Added randomized low-frequency glitch events, scan sweeps, data jitter, grid flicker, LED activity, and diagnostic pulses.
- Kept motion respectful of the operating system reduced-motion preference.
- Added project database import alongside the existing JSON download/export control.
- Updated the starter data and version metadata to 1.5.0.


## Version 1.6.0
- Added Delta Ray as the preferred local font for major hero, page, modal, and admin headings, with Oxanium fallback when Delta Ray is not installed.
- Added stronger glitch activity around open windows, including RGB edge separation, horizontal slice displacement, corner re-sync markers, and border signal sweeps.
- Added a brief window-lock animation when dialogs finish opening.
- Increased the probability of ambient window glitches while a popup is open, while preserving reduced-motion accessibility.
- Kept body text and controls in readable interface fonts.

### Delta Ray font note
The package does not redistribute font files. Install Delta Ray on the Mac to use it automatically; the site falls back to Oxanium when it is unavailable.


## Version 1.8.0
- Promoted Delta Ray to the display hierarchy for hero, page, project, modal, navigation, and admin headings.
- Delta Ray now resolves from the font installed on the host Mac; Oxanium/Orbitron remain automatic fallbacks.
- Added stronger but still brief window-edge glitches, RGB separation, scan slices, perimeter signal rails, corner re-sync effects, and idle window pulses.
- Added random display-sync events on major headings.
- Increased ambient glitch cadence to a randomized 15–60 second interval.
- Preserved reduced-motion behavior for accessibility.

### Delta Ray setup
Install your licensed Delta Ray font on macOS using Font Book, then reload the site. Font binaries are intentionally not redistributed inside this release archive.
