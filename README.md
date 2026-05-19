# OpenSquadron

OpenSquadron is an open-source, Symfony-based alternative to commercial marketing and live chat platforms like ManyChat, Chatfuel, and Wati. Currently, it implements the underlying framework and Meta WhatsApp Cloud API connectivity for a Shared Live Inbox and Subscriber management.

## Prerequisites

Before you begin, ensure you have the following installed on your Windows machine:
1. **[XAMPP](https://www.apachefriends.org/index.html)** (with PHP 8.2+ and MariaDB/MySQL).
2. **[Composer](https://getcomposer.org/)** (PHP package manager).
3. **[Cloudflared CLI](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/)** (for Cloudflare Tunnel).
4. A **Meta Developer Account** with a configured WhatsApp Business App.

---

## 1. Project Setup

1. **Install Dependencies:**
   Open a terminal in the project root and run:
   ```bash
   composer install
   ```

2. **Environment Variables:**
   Open the `.env` file and configure your database credentials. (Tokens are now securely stored in the database via the Dashboard UI).
   ```ini
   DATABASE_URL="mysql://root:@127.0.0.1:3306/opensquadron?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
   ```

## 2. Local Environment (XAMPP) Setup

To easily host this on XAMPP using a local domain (`opensquadron.local`):

1. Right-click the `setup-local.ps1` script and click **Run with PowerShell**. 
   *(Note: This requires Administrator privileges as it edits your Windows `hosts` file and XAMPP's `httpd-vhosts.conf`)*.
2. Open the **XAMPP Control Panel**.
3. **Restart Apache** and **Start MySQL**.

## 3. Database & Admin Initialization

Once MySQL is running in XAMPP, create the database, run the schema migrations, and create your Admin account:
```bash
C:\xampp\php\php.exe bin/console doctrine:database:create
C:\xampp\php\php.exe bin/console doctrine:migrations:migrate
C:\xampp\php\php.exe bin/console app:create-admin admin@example.com password123
```

## 4. Setting up the Cloudflare Tunnel

To connect your local environment to the Meta Cloud API webhook, your local server needs a public HTTPS URL (e.g., `opensquadron.your.domain`).

1. Log into your **Cloudflare Zero Trust** dashboard and create a Tunnel.
2. Route the public hostname (e.g., `opensquadron.your.domain`) to `http://opensquadron.local:80`.
3. Copy the **Tunnel Token** provided by Cloudflare.
4. Edit the `start-tunnel.bat` file in the root directory and replace the placeholder variable with your tunnel token.
5. Double-click `start-tunnel.bat` to start the tunnel.

## 5. Connecting WhatsApp & Meta Webhook

1. Log in to your OpenSquadron Dashboard (`https://opensquadron.your.domain/login`).
2. Go to the **WhatsApp -> Connect** page and input your `Phone Number ID`, `Access Token`, and create a `Verify Token`.
3. In your **Meta App Dashboard**, go to **WhatsApp -> Configuration** (do not use the generic "Webhooks" product tab).
4. Click **Edit Webhook**.
5. Set the **Callback URL** to: `https://opensquadron.your.domain/webhook/whatsapp`
6. Set the **Verify Token** to exactly match the one you saved in the OpenSquadron dashboard.
7. Click **Verify and Save**.
8. Underneath the Webhook URL, click **Manage** Webhook fields and subscribe to the `messages` event.

## 6. Going Live (Privacy Policy & Cloudflare Rules)

To receive messages from anyone in the world, your Meta App must be in **Live Mode**:
1. OpenSquadron automatically generates Meta-compliant policy pages. In the Meta Dashboard -> App Settings -> Basic, paste these URLs:
   - Privacy Policy: `https://opensquadron.your.domain/privacy`
   - Terms of Service: `https://opensquadron.your.domain/terms`
2. Toggle the App Mode at the top of the screen to **Live**.
3. **IMPORTANT:** Ensure your Cloudflare WAF or "Bot Fight Mode" is not blocking the `facebookexternalhit` crawler or POST requests to the `/webhook/whatsapp` endpoint.

You can now use the **Shared Inbox** in the OpenSquadron dashboard to chat with users in real time!

## License

This project is licensed under the [GNU General Public License v3.0 (GPL-3.0)](https://www.gnu.org/licenses/gpl-3.0.html). You are free to use, modify, and distribute this software under the terms of the GPLv3 license.