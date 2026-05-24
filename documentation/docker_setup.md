# Docker Deployment Guide

## Deploy in 3 Steps

```bash
git clone <your-repo-url>
cd OpenSquadron
```

**1. Set up your environment variables:**
```bash
cp .env.example .env
```
Edit the `.env` file with your secure passwords. (This file is ignored by git, so your secrets are safe).

**2. Choose your deployment style:**

**Option A — Behind Nginx Proxy Manager:**
```bash
docker compose -f docker-compose.npm.yml up -d
```
App is at `http://<server-ip>:6969`. Point NPM to it.
phpMyAdmin is at `http://<server-ip>:8081`.

**Option B — Scalable with HAProxy:**
```bash
docker compose -f docker-compose.haproxy.yml up -d
```
App is at `http://<server-ip>:6969`.
phpMyAdmin is at `http://<server-ip>:8081`.
Scale with `docker compose -f docker-compose.haproxy.yml up --scale app=3 -d`.

**Option C — Cloudflare Tunnel (No Public IP/Open Ports Needed):**
```bash
docker compose -f docker-compose.cf.yml up -d
```
The app port is NOT exposed to the host for security. 
Cloudflared routes traffic directly from your Cloudflare Zero Trust dashboard to the container.
phpMyAdmin is available locally at `http://<server-ip>:8081` or `http://localhost:8081`.

---

## What Happens Automatically

1. **Dockerfile builds** Apache + PHP 8.2 with all required extensions
2. **Composer installs** dependencies (production optimized)
3. **MariaDB starts** and auto-imports `skeleton.sql` on first boot
4. **Apache serves** the app with mod_rewrite enabled
5. **phpMyAdmin connects** automatically using the root password in `.env`

---

## Real-Time Development

Both variants mount your current working directory to `/var/www/html` inside the container. 
When you save a file in your IDE, the container sees it immediately.

Since the app runs in production mode (`APP_ENV=prod`), you'll need to clear the Symfony cache when you edit templates or core files.
Just double-click the included script or run it in your terminal:
- Windows: `docker_clear_cache.bat`
- Mac/Linux: `./docker_clear_cache.sh`

---

## Environment Variables (.env)

| Variable           | Description                                  |
|--------------------|----------------------------------------------|
| `APP_PORT`         | Port to expose the app on (Default: 6969)    |
| `APP_SECRET`       | Symfony secret key                           |
| `DB_NAME`          | Name of the database                         |
| `DB_USER`          | Database user                                |
| `DB_PASSWORD`      | Database user's password                     |
| `DB_ROOT_PASSWORD` | Database root password (used by phpMyAdmin)  |
| `PMA_PORT`         | Port to expose phpMyAdmin on (Default: 8081) |
| `CLOUDFLARE_TUNNEL_TOKEN` | Token for Cloudflared (CF variant only) |

---

## Useful Commands

```bash
# View logs
docker compose -f docker-compose.npm.yml logs -f

# Shell into the app
docker compose -f docker-compose.npm.yml exec app bash

# Database CLI
docker compose -f docker-compose.npm.yml exec db mysql -u root -p

# HAProxy stats (haproxy variant only)
# Open http://<server-ip>/haproxy?stats
```
