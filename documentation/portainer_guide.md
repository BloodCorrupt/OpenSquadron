# Portainer Deployment Guide (A to Z)

Deploying OpenSquadron via Portainer is extremely powerful, as it allows you to utilize GitOps (automatic updates on push) while keeping your server secure and clean.

This guide covers deploying the project using **Portainer's Git Repository Stacks** feature.

---



## Step 1: Add the OpenSquadron App Templates to Portainer

Instead of creating a manual stack, we have provided a highly customized App Template file that generates a beautiful, interactive form for you to deploy the project with all default environment variables pre-filled!

Before doing this, open `portainer-templates.json` in your repository and replace `https://github.com/BloodCorrupt/OpenSquadron` with your actual Git Repository URL.

1. Open your Portainer dashboard.
2. Go to **Settings** (gear icon in the bottom left).
3. Scroll down to **App Templates**.
4. Check **Use custom templates** and enter the raw URL to your `portainer-templates.json` file (e.g., `https://raw.githubusercontent.com/BloodCorrupt/OpenSquadron/main/portainer-templates.json`).
5. Click **Save settings**.

## Step 2: Deploy OpenSquadron via App Templates

1. Click on **App Templates** in the left sidebar.
2. You will now see 3 new interactive templates with logos:
   - **OpenSquadron (NPM Proxy)**
   - **OpenSquadron (HAProxy Scalable)**
   - **OpenSquadron (Cloudflare Tunnel)**
3. Click the variant you want to deploy.

## Step 3: Interactive Environment Variables

Because you are using the App Template, Portainer will immediately present you with a clean, labeled form with textboxes for `DB_PASSWORD`, `APP_SECRET`, etc. 

All textboxes are perfectly pre-filled with the default values from `.env.example`! You can now review them and change them securely *before* clicking deploy.

### 2. How do you access local files for development on the server?
When Portainer deploys from Git naturally, it hides your code deep inside Docker volumes. If you want a nice, easily accessible folder on your server that **automatically updates** when you push from your laptop, follow this Dev Workflow:

**The Custom Dev Folder Workflow (`APP_CODE_PATH`)**
1. SSH into your server and manually clone your repository to a nice folder you can easily reach (e.g., `/home/ubuntu/OpenSquadron`).
2. Go to Portainer and deploy using your App Template.
3. In the environment variables form, find the `APP_CODE_PATH` variable and set it to: `/home/ubuntu/OpenSquadron`.

**What happens next?**
Because you mapped a custom folder, the container will use *your* folder instead of Portainer's hidden one. 
If you push code from your laptop to GitHub, Portainer will detect the update and restart the container. As soon as the container boots, our intelligent `entrypoint.sh` detects your `.git` folder and **automatically runs `git pull`**! 

It even perfectly restores file ownership to your local Linux user so you never get `Permission Denied` errors. You can now edit the live files in `/home/ubuntu/OpenSquadron` via FTP or VS Code SSH, and the code will *also* auto-update perfectly via GitHub!

## Step 4: Enable Automatic Updates (Optional but Recommended)

You can tell Portainer to automatically pull new code and restart the stack whenever you push to GitHub!

1. Toggle **Automatic updates** to ON.
2. **Fetch interval**: You can set it to poll every 5 minutes (e.g., `5m`).
3. **Webhook**: Alternatively, you can enable the webhook and add the Portainer webhook URL to your GitHub/GitLab repository settings so updates are instant!

## Step 5: Deploy the Stack

Click the **Deploy the stack** button at the bottom of the page.

*Note: The first deployment will take a few minutes because Portainer has to build the PHP/Apache Docker image from scratch.*

---

## What Happens Automatically on Deployment?

We have wired a highly intelligent `entrypoint.sh` script into the Docker container. Once Portainer finishes building and starting the containers, the App container will automatically:

1. **Wait for the Database**: It pings MariaDB until it is fully initialized and ready to accept connections.
2. **Fix Permissions**: It ensures the internal cache and log folders are writeable by the web server (without breaking your host user's permissions).
3. **Clear Cache**: It runs `cache:clear` and `cache:warmup` so your Symfony routing and Twig templates are perfectly compiled.
4. **Migrate the Database**: It automatically runs `doctrine:migrations:migrate`. Your database schema will always be perfectly in sync with your code!
5. **Start Apache**: The application comes online.

You do **NOT** need to manually run `docker_deploy_db.bat` or SSH into the server to run commands. The container handles its entire lifecycle automatically.

## Accessing the App

Once deployed, the database is automatically seeded with a default Super Admin account:
- **Email:** `admin@opensquadron.local`
- **Password:** `admin123`
*(Make sure to change this immediately after your first login!)*

- **App**: `http://<your-server-ip>:6969` (or whatever `APP_PORT` you set).
- **phpMyAdmin**: `http://<your-server-ip>:8081`.

If you are using Cloudflare Tunnels (`docker-compose.cf.yml`), the app port is completely hidden from the internet and your server's firewall. You will route traffic directly to the `app:80` service via your Cloudflare Zero Trust dashboard.
