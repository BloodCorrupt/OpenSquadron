# WhatsApp Cloud API Connection Guide

This guide provides a comprehensive, step-by-step walkthrough to connect your WhatsApp Business Phone Number to OpenSquadron via the Meta Cloud API.

---

## Prerequisites
Before you begin, ensure you have:
1. A **Meta Developer Account** (register at [developers.facebook.com](https://developers.facebook.com)).
2. A **Meta Business Portfolio** (Business Manager account).
3. A clean, valid phone number to use for WhatsApp Business (this number must not have an active personal WhatsApp or WhatsApp Business app account; if it does, you must delete it from the mobile app first).

---

## Step 1: Create a Meta Developer App
1. Go to the [Meta App Dashboard](https://developers.facebook.com/apps) and click **Create App**.
2. Select **Other** -> **Business** (or Choose the **WhatsApp** type if presented with simplified options).
3. Provide an App Name (e.g., `OpenSquadron Gateway`) and link it to your Meta Business Portfolio.
4. Click **Create app**.

---

## Step 2: Set Up the WhatsApp Product
1. Scroll down to the list of products inside your new App Dashboard and click **Set up** on the **WhatsApp** product.
2. Accept the Meta Terms and Conditions.
3. This creates a temporary test number for your app and provides a sandbox environment. To use your own production number, follow the instructions in the dashboard under **Add phone number** to bind your real number and verify it via SMS/Voice call.

---

## Step 3: Retrieve Key Meta Credentials
Navigate to the WhatsApp **API Setup** page inside your developer sidebar and extract the following credentials:

1. **WhatsApp Business Account ID**: Identified as your Meta WhatsApp Account ID (e.g., `1297297349135038`).
2. **Phone Number ID**: **[MANDATORY]** The identifier unique to the specific phone number registered to your Meta account (e.g., `1141198275740552`).
3. **System User Access Token**:
   > [!IMPORTANT]
   > - The token displayed on the API Setup page is a **Temporary Access Token** which expires in 24 hours.
   > - For production, you **MUST** generate a **Permanent System User Access Token** in your Meta Business Suite under **Business Settings** -> **Users** -> **System Users**. Assign the system user to your App and select the `whatsapp_business_messaging` and `whatsapp_business_management` permissions.

---

## Step 4: Configure the Connection in OpenSquadron
1. Log in to your OpenSquadron Admin Dashboard.
2. Navigate to **Infrastructure Gateway** via `/whatsapp-business/connect` in your browser.
3. Fill out the **Add New WhatsApp Number** configuration wizard:
   * **Connection Label**: Enter a descriptive name (e.g., `Primary Sales Bot`, `Support Line`).
   * **Display Phone Number**: Enter your display number formatted with country code (e.g., `+1 415 740 0552`).
   * **System Account ID**: Paste your retrieved *WhatsApp Business Account ID*.
   * **Phone Number ID**: Paste your **Phone Number ID** (strictly mandatory).
   * **Secure Access Token**: Paste your *Permanent System User Access Token*.
4. Click **Initiate Connection Handshake**. OpenSquadron will validate the credentials against the Meta Graph API and save the record in your secure multi-tenant workspace.

---

## Step 5: Configure Inbound Webhooks in Meta
To receive subscriber messages in real-time in your shared inbox, you must set up a secure bridge:

1. After saving, the OpenSquadron configuration dashboard displays **Wizard Step 2: Map Webhook Endpoints**.
2. Copy the **Inbound Webhook Callback URL** (e.g., `https://yourdomain.com/webhook/whatsapp`).
   > [!WARNING]
   > Meta requires an **HTTPS** URL. If testing locally, you can tunnel your local server via a service like Cloudflare Tunnel or ngrok to get a secure public HTTPS endpoint.
3. Copy the **Cryptographic Verify Token** displayed in Step 2.
4. Go back to your **Meta App Dashboard** -> **WhatsApp** -> **Configuration**.
5. Click **Edit** next to Webhooks:
   * Paste the copied **Inbound Webhook Callback URL** into the *Callback URL* field.
   * Paste the **Cryptographic Verify Token** into the *Verify Token* field.
   * Click **Verify and save**.
6. **CRITICAL**: Once verified, click **Manage** under *Webhook fields* on the same page. Find the **messages** row and click **Subscribe**. Without subscribing to `messages`, Meta will not forward inbound customer messages to your server.

---

## Step 6: Verify and Test the Setup
1. Open your shared inbox in OpenSquadron.
2. Send a WhatsApp message from a personal phone number to your newly configured business phone number.
3. Verify that the message instantly appears in your shared inbox:
   * The system will automatically create a new subscriber profile if they are messaging for the first time.
   * The **24-hour Customer Service Window** countdown timer will activate, showing you the exact countdown during which you can reply using free-form responses.
   * If the window closes, verify that the entry locks and informs you that only approved templates can be used to re-initiate a chat.
