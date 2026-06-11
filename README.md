# OSINT Dispatch — Static Site

AdSense-ready cybersecurity/OSINT blog. Auto-deploys to GoDaddy via FTP on every push to `main`.

## One-time setup
1. Push this repo to GitHub (branch: `main`)
2. GoDaddy cPanel → FTP Accounts → note server, username, password
3. GitHub repo → Settings → Secrets and variables → Actions → add:
   - `FTP_SERVER` (e.g. ftp.yourdomain.com or server IP)
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
4. Before first deploy, find-replace in `site/`:
   - `https://yourdomain.com` → your real domain
   - `contact@yourdomain.com` → your real email
5. Push (or run the workflow manually from the Actions tab). Site goes live in ~1–2 min.

## Structure
- `site/` — everything that gets deployed to `public_html/`
- `.github/workflows/deploy.yml` — FTP auto-deploy pipeline

## Editing
Any change pushed to `main` inside `site/` deploys automatically.
