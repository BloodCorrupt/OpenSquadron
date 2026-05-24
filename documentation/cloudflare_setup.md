# Super Admin: Cloudflare Integration Guide

The OpenSquadron platform utilizes **Cloudflare for SaaS** (Custom Hostnames) to automatically provision enterprise-grade SSL certificates and DDoS protection for your Resellers' custom white-label domains.

This guide explains how to set up your Cloudflare account to communicate with OpenSquadron so the SSL validation process is completely automated.

---

## Prerequisites
- A Cloudflare account.
- A "Helper Domain" added to your Cloudflare account (e.g., `proxy.yourdomain.com` or `ns.yourdomain.com`). This is the domain your resellers will point their traffic to.
- Cloudflare for SaaS must be enabled on your Cloudflare account (you get 100 free custom hostnames on the free tier).

---

## Step 1: Locate Your Zone ID
The Zone ID is the unique identifier for your Helper Domain in Cloudflare.

1. Log into your Cloudflare dashboard.
2. Select the domain you are using as your Helper Domain (e.g., `yourdomain.com`).
3. Scroll down on the **Overview** page.
4. On the right sidebar, locate the **API** section.
5. Copy the **Zone ID** (a long alphanumeric string).

---

## Step 2: Create a Custom API Token
OpenSquadron needs an API token to automatically add your resellers' custom domains to Cloudflare on your behalf.

1. In the same Cloudflare dashboard, click **Get your API token** (or go to *My Profile > API Tokens*).
2. Click **Create Token**.
3. Scroll down and click **Create Custom Token** at the bottom.
4. **Token Name:** Give it a recognizable name (e.g., `OpenSquadron SaaS Token`).
5. **Permissions:** You must add the following **three** permissions precisely:
   - `Zone` — `Zone Settings` — `Edit`
   - `Zone` — `SSL and Certificates` — `Edit`
   - `Zone` — `Custom Hostnames` — `Edit`
6. **Zone Resources:** Select `Include` — `Specific zone` — `[Your Helper Domain]`.
7. Click **Continue to summary**, then **Create Token**.
8. **CRITICAL:** Copy the generated Token immediately. Cloudflare will never show this to you again.

---

## Step 3: Configure OpenSquadron
Now that you have your Zone ID and API Token, you need to plug them into OpenSquadron.

1. Log into your OpenSquadron platform as the Super Admin.
2. Navigate to **Settings > Cloudflare Settings** in the left sidebar.
3. **Helper Domain:** Enter the specific subdomain you want resellers to point to (e.g., `proxy.yourdomain.com`). 
4. **Cloudflare API Token:** Paste the Token you generated in Step 2.
5. **Cloudflare Zone ID:** Paste the Zone ID from Step 1.
6. Click **Save Settings**.

---

## Step 4: Verify the Setup
To ensure everything works:
1. Log into a test Reseller account (or use your own).
2. Navigate to **Settings > Branding & Domains**.
3. Enter a test custom domain (e.g., `test.example.com`) and save.
4. If the Cloudflare integration is successful, the screen will instantly display a unique `_acme-challenge` CNAME record for SSL validation. If it fails, an error message will display.
5. You can also verify this by logging into Cloudflare, going to your Helper Domain -> **SSL/TLS** -> **Custom Hostnames**, and ensuring the test domain appears in the list.
