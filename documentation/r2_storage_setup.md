# Cloudflare R2 Storage Setup Guide

OpenSquadron uses Cloudflare R2 (or any S3-compatible cloud storage) to host all images, avatars, branding logos, and chat media. To maximize performance and eliminate server resource overhead, files are uploaded directly from the client's web browser to the cloud bucket using presigned URLs.

This guide outlines how to configure your Cloudflare R2 bucket and connect it to OpenSquadron.

---

## Step 1: Create a Cloudflare R2 Bucket

1. Log into your [Cloudflare Dashboard](https://dash.cloudflare.com).
2. In the left sidebar, click **R2** (under *Object Storage*).
3. Click **Create Bucket**.
4. **Bucket Name:** Enter a unique name (e.g., `opensquadron-media`).
5. Click **Create Bucket**.

---

## Step 2: Configure CORS Policy (Critical for Direct Uploads)

Since file uploads bypass the PHP server and go directly from the browser to Cloudflare R2, you **must** configure Cross-Origin Resource Sharing (CORS) on your bucket.

1. Select your newly created bucket in the Cloudflare dashboard.
2. Go to the **Settings** tab.
3. Select **CORS Policy** from the left-hand settings sidebar and click **Add CORS Policy** (or **Edit CORS Policy**).
4. Paste the following configuration (or adapt the Allowed Origins to match your specific domain):

```json
[
  {
    "AllowedOrigins": ["*"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["content-type", "x-amz-*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

5. Click **Save**.

---

## Step 3: Enable Public Access

For users and visitors to see the uploaded avatars, logos, and media, the bucket must have a public URL.

1. Still in your bucket's **Settings** tab, use the left-hand settings sidebar to configure public access:
2. You can choose one of two options:
   - **Option A (Recommended - Custom Domain):** Select **Custom Domains** in the settings sidebar, click **+ Add** (or **Connect Domain**), type in your custom subdomain (e.g. `media.yourdomain.com`), and follow the steps to connect it.
   - **Option B (Public Development URL):** Select **Public Development URL** in the settings sidebar, and click **Enable** to turn on the auto-generated `r2.dev` public subdomain.
3. Copy the public domain URL (e.g. `https://media.yourdomain.com` or `https://pub-xxxx.r2.dev`). **Do not include a trailing slash.**

---

## Step 4: Generate API Credentials

OpenSquadron needs API credentials to generate presigned upload URLs and manage files (including auto-deletion settings).

1. Go back to the main **R2** page in your Cloudflare dashboard.
2. In the right-hand sidebar (or top right), click **Manage R2 API Tokens**.
3. Under **Account API Tokens**, click the **Create Account API token** button (recommended for production systems) or click **Create User API token**.
4. **Token Name:** Enter a recognizable name (e.g., `OpenSquadron Storage Token`).
5. **Permissions:** Select **Admin Read & Write**. *(Note: This permission level is required to allow OpenSquadron to automatically set and update your bucket's **Object Lifecycle Rules** via API).*
6. **Bucket Scopes:** Select **Apply to specific buckets** and select your media bucket (or choose **All buckets**).
7. Click **Create API Token** (at the bottom of the page).
8. Copy the **Access Key ID** and **Secret Access Key** immediately (under *Use the following credentials for S3 clients*). Keep them secure.
9. You can also copy your **Account ID** directly from this page (see Step 5 below).

---

## Step 5: Locate Your Account ID

Your Cloudflare Account ID is needed to connect OpenSquadron to your R2 endpoint.

**Option A (Easiest - From Token Page):**
1. On the token creation success page, look at the **Use jurisdiction-specific endpoints for S3 clients** section.
2. Copy the subdomain part of the URL (e.g., if the URL is `https://9f064d0cfe01e617d4e48e368f0fc000.r2.cloudflarestorage.com`, your Account ID is `9f064d0cfe01e617d4e48e368f0fc000`).

**Option B (From Dashboard):**
1. Go back to the main **R2** dashboard page.
2. In the right-hand sidebar, locate the **Account ID** string under the API section.
3. Copy the string.

---

## Step 6: Configure OpenSquadron

You can configure storage settings globally or per-client depending on roles.

### Global / Super Admin Settings
If you are the platform operator:
1. Log in as Super Admin and go to **Admin Panel > Media Storage** in the navigation bar.
2. Fill in:
   - **Account ID**
   - **Access Key ID**
   - **Secret Access Key**
   - **Bucket Name**
   - **Public Access URL**
3. Save changes. This becomes the fallback storage for all users.

### Reseller Settings
Resellers can configure their own cloud storage:
1. Go to **Admin Panel > Media Storage**.
2. Fill in R2 credentials. This will isolate reseller customer uploads to the reseller's custom bucket.

### Packages & Enforcing Custom Storage
Super Admins and Resellers can choose how their customers store media via **Subscription Packages**:
1. Go to **Admin Panel > Subscription Packages** and edit a package.
2. Under **Storage Policy**, select the preferred mode:
   - **Use Shared Storage:** Customers inherit storage settings from the Reseller or Super Admin.
   - **Enforce Custom R2 Storage:** Customers must enter their own credentials to be able to upload.
   - **Allow Choice:** Customers can use the shared storage or toggle to input their own custom bucket.
