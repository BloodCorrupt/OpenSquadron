# Nginx Proxy Manager (NPM) Guide

If you are using the **OpenSquadron (NPM Proxy)** deployment template in Portainer or running `docker-compose.npm.yml`, your server is running the app on a local port (default: `6969`). 

To make your application accessible to the internet with a secure `https://` domain (which is strictly required by Meta for Webhooks), you need to route traffic from your domain to this port using Nginx Proxy Manager.

## Step 1: Add a Proxy Host

1. Log into your **Nginx Proxy Manager** dashboard (usually at `http://<your-server-ip>:81`).
2. Go to **Hosts** -> **Proxy Hosts**.
3. Click the **Add Proxy Host** button.

## Step 2: Configure the Details Tab

Fill out the form exactly like this:

- **Domain Names**: Type your exact domain (e.g., `opensquadron.your-domain.com`) and press Enter to lock it in as a chip.
- **Scheme**: `http`
- **Forward Hostname / IP**: 
  - *If NPM is running in Docker on the same server:* You cannot use `127.0.0.1` or `localhost` (that points inside the NPM container). Use your **Server's Public IP** or the Docker bridge IP (e.g., `172.17.0.1`).
- **Forward Port**: `6969` (or whatever `APP_PORT` you set in Portainer/`.env`).
- **Cache Assets**: Toggle **ON** (optional but recommended).
- **Block Common Exploits**: Toggle **ON**.
- **Websockets Support**: Toggle **ON**.

## Step 3: Configure the SSL Tab

Meta Webhooks **will absolutely fail** if you do not have a valid SSL certificate. NPM makes this 1-click simple:

1. Click the **SSL** tab at the top of the modal.
2. In the dropdown, select **Request a new SSL Certificate**.
3. Toggle **Force SSL** to **ON**.
4. Toggle **HTTP/2 Support** to **ON**.
5. Enter your email address for Let's Encrypt notifications.
6. Check "I Agree to the Let's Encrypt Terms of Service".

## Step 4: Configure Advanced (Optional but Recommended)

By default, OpenSquadron allows file uploads up to 64MB for attachments. To ensure Nginx doesn't block large file uploads, click the **Advanced** tab and paste the following line:

```nginx
client_max_body_size 64M;
```

## Step 5: Save and Test

1. Click **Save**. It may take 15-30 seconds as NPM communicates with Let's Encrypt to generate your certificate.
2. Once saved, open a new browser tab and navigate to `https://opensquadron.your-domain.com`.

You should instantly see the beautiful OpenSquadron login screen, completely secured with the lock icon!
