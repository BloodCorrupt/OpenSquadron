# OpenSquadron

OpenSquadron is an open-source, Symfony-based alternative to commercial marketing and live chat platforms like ManyChat, Chatfuel, and Wati. Currently, it implements the underlying framework and Meta WhatsApp Cloud API connectivity for live chat.

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
   Open the `.env` file and configure your Meta/WhatsApp credentials. Replace the placeholders with your actual Meta App details:
   ```ini
   WHATSAPP_VERIFY_TOKEN="your_custom_verify_token"
   WHATSAPP_ACCESS_TOKEN="your_admin_access_token"
   WHATSAPP_PHONE_NUMBER_ID="your_phone_number_id"
   DATABASE_URL="mysql://root:@127.0.0.1:3306/opensquadron?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
   ```

## 2. Local Environment (XAMPP) Setup

To easily host this on XAMPP using a local domain (`opensquadron.local`):

1. Right-click the `setup-local.ps1` script and click **Run with PowerShell**. 
   *(Note: This requires Administrator privileges as it edits your Windows `hosts` file and XAMPP's `httpd-vhosts.conf`)*.
2. Open the **XAMPP Control Panel**.
3. **Restart Apache** and **Start MySQL**.

## 3. Database Initialization

Once MySQL is running in XAMPP, create the application database by running:
```bash
C:\xampp\php\php.exe bin/console doctrine:database:create
```

## 4. Setting up the Cloudflare Tunnel

To connect your local environment to the Meta Cloud API webhook, your local server needs a public HTTPS URL (e.g., `opensquadron.your.domain`).

1. Log into your **Cloudflare Zero Trust** dashboard and create a Tunnel.
2. Route the public hostname (e.g., `opensquadron.your.domain`) to `http://opensquadron.local:80`.
3. Copy the **Tunnel Token** provided by Cloudflare.
4. Edit the `start-tunnel.bat` file in the root directory and replace the placeholder variable with your tunnel token:
   ```bat
   set TUNNEL_TOKEN=your_cloudflare_tunnel_token_here
   ```
5. Double-click `start-tunnel.bat` to start the tunnel.

## 5. Meta Webhook Setup

1. Go to your Meta App Dashboard > WhatsApp > Configuration.
2. Click **Edit Webhook**.
3. Set the **Callback URL** to: `https://opensquadron.your.domain/webhook/whatsapp`
4. Set the **Verify Token** to exactly match the `WHATSAPP_VERIFY_TOKEN` you set in your `.env` file.
5. Click **Verify and Save**.
6. Manage Webhook fields and subscribe to the `messages` event.

### Testing the connection

You can verify outgoing messages are working by visiting this test URL in your browser:
`http://opensquadron.local/whatsapp/test?to=YOUR_PHONE_NUMBER_WITH_COUNTRY_CODE`

If set up correctly, incoming messages to your business number will also automatically reply with an acknowledgment message confirming the Webhook connection!

## License

This project is licensed under the [GNU General Public License v3.0 (GPL-3.0)](https://www.gnu.org/licenses/gpl-3.0.html). You are free to use, modify, and distribute this software under the terms of the GPLv3 license.