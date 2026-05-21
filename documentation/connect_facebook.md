# Facebook Messenger & Page Connection Guide

This guide provides a step-by-step walkthrough to connect your Facebook Page / Messenger channel to OpenSquadron using **Facebook Login for Business (OAuth)**. This permits both messaging capabilities and future full page post/automation features.

---

## Prerequisites
Before you begin, ensure you have:
1. A **Meta Developer Account** (register at [developers.facebook.com](https://developers.facebook.com)).
2. A **Facebook Page** (you must be an Admin of the page you wish to connect).
3. A public secure **HTTPS URL** pointing to your OpenSquadron server (e.g. via Cloudflare Tunnel or ngrok) for OAuth redirect callbacks and webhooks.

---

## Step 1: Create a Meta Developer App
1. Go to the [Meta App Dashboard](https://developers.facebook.com/apps) and click **Create App**.
2. Select **Other** -> **Business** (Facebook Login for Business requires the Business app type).
3. Provide an App Name (e.g., `OpenSquadron Automation App`) and link it to your Meta Business Portfolio or keep it personal.
4. Click **Create app**.

---

## Step 2: Set Up Facebook Login for Business (OAuth)
1. Scroll down to the list of products inside your new App Dashboard and click **Set up** on the **Facebook Login for Business** product.
2. In the developer sidebar, expand **Facebook Login for Business** -> **Settings**.
3. Under **Client OAuth settings**:
   * Add your callback URL to **Valid OAuth Redirect URIs**:
     `https://yourdomain.com/admin/facebook/callback` (replace with your secure domain).
   * Ensure **Web OAuth Login** is enabled.
4. Click **Save Changes**.

---

## Step 3: Retrieve App Credentials
You will need the following credentials from your Meta Developer Portal:
1. **App ID**: Located at the top bar of your Meta App Dashboard (e.g., `582910394019284`).
2. **App Secret**: Navigate to **App settings** -> **Basic** in the sidebar, click **Show** next to *App Secret*, and copy it.

---

## Step 4: Initiate Connection in OpenSquadron
1. Log in to your OpenSquadron Admin Dashboard.
2. Navigate to **Connect Facebook** under the *Bot Channels* dropdown menu (or go to `/admin/facebook/connect` directly).
3. Under the **Facebook Login Credentials** section:
   * **Connection Label (Optional)**: Provide a friendly name for management (e.g., `Corporate Page Flow`).
   * **Facebook App ID**: Paste your retrieved *App ID*.
   * **Facebook App Secret**: Paste your *App Secret*.
4. Click **CONNECT WITH FACEBOOK**.
5. You will be redirected to the Facebook OAuth authorization screen requesting the following scopes:
   * `pages_show_list`
   * `pages_read_engagement`
   * `pages_manage_metadata`
   * `pages_messaging` (for bot automated responses)
   * `pages_manage_posts` (for future post automation)
   * `pages_read_user_content`
6. Authorize the application and select the pages you want to permit access to.

---

## Step 5: Select Page to Link
1. Once authorized, you will be redirected back to the OpenSquadron Page Selection screen.
2. The page lists all retrieved Facebook Pages that you authorized.
3. Click **Connect Page** next to the specific Facebook Page you wish to manage. OpenSquadron will automatically exchange the authorization code for a non-expiring Page Access Token and encrypt it securely in the database.

---

## Step 6: Configure Inbound Webhooks in Meta
To receive subscriber messages and triggers in real-time, configure the webhook:
1. Navigate back to the Facebook Connection page in OpenSquadron. Your newly connected page will be listed under **Connected Pages**.
2. Click **Edit** (or look at Step 2 configuration panel on the page) to find the Webhook configuration details:
   * Copy the **Messenger Webhook Callback URL** (e.g., `https://yourdomain.com/webhook/facebook`).
   * Copy the generated cryptographic **Verify Token**.
3. Go back to your **Meta App Dashboard** -> **Messenger** -> **Webhook Settings** (or add **Webhooks** product).
4. Click **Configure** / **Edit Webhook**:
   * Paste the **Webhook Callback URL**.
   * Paste the **Verify Token**.
   * Click **Verify and save**.
5. **CRITICAL**: Once verified, click **Manage** or **Add Subscriptions** under the Webhooks section. Find the **messages** and **messaging_postbacks** rows and click **Subscribe**. Without subscribing, Meta won't forward customer replies or actions to your webhook handler.

---

## Step 7: Development Mode vs. Live Mode (App Review)

Understanding App Mode is crucial for testing and deployment:

### A. Development Mode (Default)
While your Meta App is in **Development Mode**:
* **Connection / OAuth**: Only **App Admins, App Developers, and App Testers** registered inside your Meta Developer Dashboard (under *App Roles* -> *Roles*) can initiate the "CONNECT WITH FACEBOOK" login flow. External users will get a "Feature Unavailable" error.
* **Messaging / Testing**: The messaging webhook will only trigger for messages sent by developers/testers who have roles in the Meta App. Public user messages will be ignored by Meta.
* *Best Practice*: Perform all bot design and webhook verification using your developer accounts or explicitly added test Facebook profiles in this mode.

### B. Live Mode (Going Public)
To allow standard public page connections and receive messages from the general public:
1. In the top bar of your Meta App Dashboard, toggle the app from **Development** to **Live**.
2. **App Review Requirements**: Toggling to Live mode requires that you submit your Meta App for **App Review** and get approved for the necessary permissions:
   * `pages_messaging` (to reply automatically to customer threads)
   * `pages_show_list` & `pages_read_engagement` (to fetch pages during connection)
   * `pages_manage_metadata` (to establish the webhook subscriptions on pages)
   * `pages_manage_posts` & `pages_read_user_content` (if using future post scheduling/automation features)
3. You will also need to submit Business Verification documents to verify your Meta Business Account in the Business Settings portal.
