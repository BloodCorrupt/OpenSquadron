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

Choose **one** of the following methods to initialize your database:

### Option A: Clean Import from Skeleton (Recommended)
This imports the complete up-to-date schema, seeds the initial tables, and marks the Doctrine migrations history as complete in a single step.

#### Method 1: Using XAMPP Shell / Command Line (Fastest)
1. **Open XAMPP Control Panel** and click the **Shell** button on the right side.
2. **Create the database** by running:
   ```bash
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS opensquadron CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
3. **Import the skeleton database file**:
   ```bash
   mysql -u root opensquadron < skeleton.sql
   ```
   *(Note: XAMPP's default MySQL installation uses user `root` with no password. If you have set a custom password, append `-p` to the commands and enter your password when prompted)*.

#### Method 2: Using phpMyAdmin (Web GUI)
1. Open your web browser and go to `http://localhost/phpmyadmin/`.
2. Click on **SQL** in the top navigation tab and run:
   ```sql
   CREATE DATABASE IF NOT EXISTS `opensquadron` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Click on the newly created **opensquadron** database in the left-hand sidebar list.
4. Click the **Import** tab at the top.
5. Click **Choose File** and select the `skeleton.sql` file in the root of the project.
6. Scroll down to the bottom of the page and click the **Import** (or **Go**) button.

#### Default Admin Login:
Once imported, you can log in to the application at `https://opensquadron.local/login` or `http://localhost/login` with:
- **Email:** `admin@opensquadron.local`
- **Password:** `admin123` *(change immediately after first login)*

---

### Option B: Build Schema via Doctrine Migrations
If you want to build the database step-by-step from code mappings and create a custom admin user:

1. **Create the database**:
   ```bash
   C:\xampp\php\php.exe bin/console doctrine:database:create
   ```
2. **Execute migrations**:
   ```bash
   C:\xampp\php\php.exe bin/console doctrine:migrations:migrate
   ```
3. **Create your admin account**:
   ```bash
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

## 6. Connecting Facebook (OAuth & Login for Business)

For step-by-step setup details, refer to the [Facebook Page Connection Guide](file:///c:/Users/Bloodtek/Documents/dev/OpenSquadron/documentation/connect_facebook.md).
1. Go to the **Facebook -> Connect** page inside the OpenSquadron Dashboard.
2. Enter your **Facebook App ID** and **App Secret** (make sure your App type is **Business** in the Meta Developer portal, and your valid redirect URI is configured to `https://opensquadron.your.domain/admin/facebook/callback`).
3. Click **Connect with Facebook** to authorize the application.
4. Select the Facebook Page you want to connect from the list back in OpenSquadron.
5. Set up your Webhook Callback URL: `https://opensquadron.your.domain/webhook/facebook` with your unique verify token in the Meta Developer dashboard under the **Webhooks** product, and subscribe to `messages` and `messaging_postbacks` events.

## 7. Going Live (Privacy Policy, App Review & Cloudflare Rules)

To receive messages from anyone in the world, your Meta App must be in **Live Mode**:
1. OpenSquadron automatically generates Meta-compliant policy pages. In the Meta Dashboard -> App Settings -> Basic, paste these URLs:
   - Privacy Policy: `https://opensquadron.your.domain/privacy`
   - Terms of Service: `https://opensquadron.your.domain/terms`
2. **App Review**: Submit your Meta App for review for the necessary permissions (`whatsapp_business_messaging` for WhatsApp; or `pages_messaging`, `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata` etc. for Facebook).
3. Toggle the App Mode at the top of the screen to **Live**.
4. **IMPORTANT:** Ensure your Cloudflare WAF or "Bot Fight Mode" is not blocking the `facebookexternalhit` crawler or POST requests to the `/webhook/whatsapp` and `/webhook/facebook` endpoints.

You can now use the **Shared Inbox** in the OpenSquadron dashboard to chat with users in real time!

## License

This project is licensed under the [GNU General Public License v3.0 (GPL-3.0)](https://www.gnu.org/licenses/gpl-3.0.html). You are free to use, modify, and distribute this software under the terms of the GPLv3 license.