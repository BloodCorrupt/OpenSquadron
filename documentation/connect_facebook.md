# 📘 Facebook Messenger & Page Connection Guide

This guide will show you how to connect your Facebook Page to OpenSquadron so the bot can automatically talk to your customers. 

We use **Facebook Login for Business**. It sounds complex, but it just means "logging in with Facebook" to link your page.

Follow these steps one by one. Do not skip any!

---

## 📋 What You Need Before You Start
1. A **Facebook account** that is the **Admin** of the Facebook Page you want to connect.
2. A **Meta Developer Account** (it is free, just log in at [developers.facebook.com](https://developers.facebook.com) using your normal Facebook account).
3. A secure **HTTPS URL** for your OpenSquadron server (e.g., `https://opensquadron.yourdomain.com`). 
   > [!IMPORTANT]
   > Facebook requires a secure website address starting with `https://`. Standard `http://` will **not** work.

---

## 🛠️ Step 1: Create Your Facebook Developer App
1. Go to [developers.facebook.com/apps](https://developers.facebook.com/apps) and click the green **Create App** button.
2. Under "What do you want your app to do?", select **Other** and click **Next**.
3. Under "Select an app type", select **Business** and click **Next**.
4. Fill in the details:
   * **App Name**: Type anything you want (e.g., `My OpenSquadron Bot`).
   * **App Contact Email**: Your email.
   * **Business Portfolio**: Leave it as "No Business Portfolio selected" (or choose your business if you have one).
5. Click **Create App** and enter your Facebook password when prompted.

---

## 🔐 Step 2: Set Up Facebook Login (OAuth)
1. On your App Dashboard, scroll down to find **Facebook Login for Business** and click **Set up**.
2. Look at the left sidebar menu. Click on **Facebook Login for Business** -> **Settings**.
3. Under **Client OAuth settings**:
   * Find the box that says **Valid OAuth Redirect URIs**.
   * **Do not guess this link!** Go to your OpenSquadron page under **Bot Channels** -> **Connect Facebook**. Look at **Step 1: Facebook Login Credentials** and copy the **Valid OAuth Redirect URI** shown in the blue box.
   * Paste that exact link into this box. It should look like `https://your-domain.com/facebook/callback` (where `your-domain.com` is your actual site address).
   * Make sure the switch for **Web OAuth Login** is turned **ON** (Yes).
4. Click the **Save Changes** button at the bottom-right.

---

## 🔑 Step 3: Copy Your App Credentials
You need two keys to link Facebook with OpenSquadron:
1. **App ID**: Look at the top bar of your Meta Developer page. Copy the long number next to "App ID" (e.g., `582910394019284`).
2. **App Secret**: 
   * In the left sidebar menu, click **App settings** -> **Basic**.
   * Look for the **App secret** field. Click the **Show** button.
   * Copy the code that appears. (This is like your app's password, keep it safe!).

---

## 🔗 Step 4: Connect OpenSquadron to Your App
1. Log in to your OpenSquadron Admin Dashboard.
2. Go to **Bot Channels** (top menu) -> **Connect Facebook** (or go to `/admin/facebook/connect` directly).
3. Under **1. Facebook Login Credentials**:
   * **Connection Label (Optional)**: Type any nickname to help you identify it (e.g., `Main Bot Connection`).
   * **Facebook App ID**: Paste the **App ID** you copied in Step 3.
   * **Facebook App Secret**: Paste the **App Secret** you copied in Step 3.
4. Click the blue **CONNECT WITH FACEBOOK** button at the bottom.
5. A Facebook popup window will appear:
   * Log in if prompted.
   * Select the Facebook Pages you want the bot to manage.
   * Click **Next**, allow all requested permissions, and click **Done**.
   * Click **OK** to close the popup.

---

## 🗂️ Step 5: Choose Which Page to Activate
1. After completing Step 4, you will be redirected to the OpenSquadron **Page Selection Screen**.
2. You will see a list/grid of all the Facebook Pages you just authorized.
3. Click the **Connect Page** button under the page you want to active.
4. OpenSquadron will now securely encrypt your page token and save it to the database.

---

## 📡 Step 6: Set Up the Webhook (Message Pipeline)
A webhook is the pipeline that sends messages from Facebook to OpenSquadron in real-time.
1. In OpenSquadron, go back to the Facebook connection page. Under the **2. Map Webhook Endpoints** section:
   * Copy the **Messenger Webhook Callback URL** (e.g., `https://your-domain.com/webhook/facebook`).
   * Copy the **Verify Token** (Wait until you save the connection in Step 5 to see it).
2. Go back to your [Meta Developer Dashboard](https://developers.facebook.com/apps):
   * In the left menu, click **Add Product** (or scroll to the bottom of the sidebar) and add **Webhooks**.
   * Go to **Webhooks** in the sidebar.
   * Under the dropdown menu at the top, select **Page**.
   * Click the **Subscribe to this object** button (or click **Edit Subscription**):
     * **Callback URL**: Paste the URL you copied from OpenSquadron.
     * **Verify Token**: Paste the token you copied from OpenSquadron.
     * Click **Verify and save**.
3. **🚨 CRITICAL STEP (DO NOT SKIP!)**:
   * In the Webhooks event list, scroll down to find:
     * `messages`
     * `messaging_postbacks`
   * Click the **Subscribe** button next to both of them. 
   * *If you do not do this, Facebook will not send messages to your bot, and it will remain silent!*

---

## 🧪 Step 7: How to Test and Run in Development Mode (No App Review Needed!)
By default, your Facebook App starts in **Development Mode**. 

The great news is **you do NOT need to go through Meta's painful App Review process** to use your bot! You can keep the App in Development Mode forever to build, test, and use the bot with your team. 

However, because Facebook protects user privacy, Development Mode has a few strict rules:
1. **Who can link Pages?** Only you (the Facebook account that created the Meta Developer App) or people you explicitly add as Admins, Developers, or Testers in the App Roles can click "Connect with Facebook".
2. **Who can talk to the bot?** Only people who have an App Role (Admins, Developers, Testers) can send messages to your Facebook Page and get a response. If a regular customer messages your Page, the Facebook system will silently ignore them (no webhook events will be sent).

### 👥 How to Add Testers/Team Members
If you want other people (like your team members, clients, or test profiles) to test the bot:
1. In the left sidebar of your [Meta Developer Dashboard](https://developers.facebook.com/apps), click **App Roles** -> **Roles**.
2. Click the **Add Testers** (or **Add Developers**) button.
3. Enter their Facebook Username, Facebook ID, or name, then click **Submit**.
4. The person you added must log in to their own Facebook account, go to [developers.facebook.com/requests](https://developers.facebook.com/requests), and click **Accept** on your invitation.
5. Once accepted, they can now send messages to your connected Facebook Page, and the bot will reply to them instantly!

---

## 🚀 Step 8: How to Go Live to the Public (Optional)
Only follow this step if you want the chatbot to respond to **strangers and the general public** who visit your Facebook Page.

To make the app public, you must toggle it to **Live Mode** in the Meta Developer Dashboard. To do this, Facebook requires **App Review**:
1. Go to **App settings** -> **Basic** in the Meta Developer Dashboard.
2. In the fields for **Privacy Policy URL** and **Terms of Service URL**, paste the compliance links generated by OpenSquadron:
   * You can copy these links directly from the OpenSquadron Facebook connection page under Step 2 (e.g. `https://your-domain.com/privacy` and `https://your-domain.com/terms`).
3. Click **App Review** -> **Permissions and Features** in the sidebar.
4. Request permissions for:
   * `pages_messaging` (allows the bot to send replies)
   * `pages_show_list` (allows OpenSquadron to list pages you own)
   * `pages_manage_metadata` (allows OpenSquadron to set up webhooks automatically)
5. Record a short screencast of you interacting with the bot in Development Mode (showing it replies to your message), submit it to Facebook, and wait for their approval.
