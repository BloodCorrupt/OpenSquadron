# OpenSquadron White-Label Domain Setup Guide

Welcome to the **White-Label Branding** documentation. This guide explains how to configure a custom domain (e.g., `app.youragency.com`) so that your clients see your custom branding instead of the default OpenSquadron interface when logging in or registering.

> [!IMPORTANT]
> You **must** configure a custom domain before you are allowed to enable **Public Registration** for your clients.

---

## Step 1: Input Your Custom Domain
Decide on a subdomain or root domain you wish to use for your control panel and enter it in the **Custom Domain** field inside the Branding Settings panel. Do not include `http://` or `https://`.
- **Example Subdomain:** `clients.myagency.com`
- **Example Root Domain:** `www.myagency-app.com`

---

## Step 2: Save Your Domain
Enter your domain into the "Custom Domain" field and click Save. 
**Magic happens here!** The OpenSquadron platform will instantly connect to Cloudflare via our API and automatically generate a unique SSL Validation string specifically for your domain.

---

## Step 3: Add DNS Records
Once you save your domain, the UI will update to display two specific CNAME records. Log into your domain registrar's DNS settings (GoDaddy, Namecheap, Cloudflare, etc.) and add both records:

1. **SSL Validation Record:**
   - Type: `CNAME`
   - Name: `_acme-challenge.yoursubdomain` (The exact name will be provided in the UI)
   - Value: `[unique-hash].dcv.cloudflare.com` (The exact value will be provided in the UI)

2. **Traffic Routing Record:**
   - Type: `CNAME`
   - Name: `yoursubdomain`
   - Value: The Helper Domain displayed in your UI.

> [!NOTE]
> DNS changes may take up to several hours to propagate. Once Cloudflare detects the `_acme-challenge` record, your SSL certificate will activate automatically!

---

## Step 4: Configure Branding Settings

1. In the sidebar, navigate to **Settings > Branding & Domains**.
2. **Brand Name:** Enter your company name (e.g., "My Agency"). This replaces "OpenSquadron" across the application interface and emails.
3. **Brand Logo:** Upload a square, high-quality PNG or JPG of your logo.
4. Click **Save Settings**.

---

## Step 5: Enable Public Registration (Optional)

Once your custom domain is active and saved in your settings, the system authorizes you to enable public sign-ups for your clients.

1. Navigate to **Settings > SMTP Settings**.
2. Ensure your email configuration is fully complete.
3. Toggle the **Enable Public Registration** switch to ON.
4. Click **Save Settings**.
