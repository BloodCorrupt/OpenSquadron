# CSS Customization & Theme Architecture Documentation

This document outlines the architecture, styling conventions, and database integration for the white-label custom CSS configuration and the user-wide Light Mode (White Mode) styling system.

---

## 1. White-Label Custom CSS

Super Admins and Resellers (accounts of type `super_admin` or `admin`) can input raw custom CSS in their white-label settings screen to brand their instance. This custom CSS is loaded dynamically and injected globally.

### Database Persistence
- **Table**: `reseller_branding` (created / updated via [skeleton.sql](../skeleton.sql)).
- **Entity**: [ResellerBranding.php](../src/Entity/ResellerBranding.php).
- **Property**: `$customCss` (Doctrine type `text`, nullable).
- **Controller**: Saved asynchronously/via form submit in [BrandingSettingsController.php](../src/Controller/BrandingSettingsController.php).

### Twig Integration
A custom Twig function gets the current domain's custom CSS block:
- **Extension**: [BrandingExtension.php](../src/Twig/BrandingExtension.php) -> function `get_custom_css()`.
- **Domain Fallback**: If the platform is accessed locally (e.g., `localhost` or base platform domain), the function dynamically identifies the logged-in user's workspace owner branding context to load their custom CSS block safely.
- **Injected Locations**:
  - Global base template: [base.html.twig](../templates/base.html.twig) (within the `<head>` tag).
  - Clean canvas layout: [empty.html.twig](../templates/empty.html.twig).
  - Security/Authentication pages: `login.html.twig`, `forgot.html.twig`, `reset.html.twig`.

```twig
{# Loaded last in <head> to allow overriding all system styles #}
{% if get_custom_css() is not empty %}
    <style id="white-label-custom-css">
        {{ get_custom_css()|raw }}
    </style>
{% endif %}
```

---

## 2. Light Mode (White Mode) Theme Architecture

The system uses a user-wide Dark (default) and Light Mode. Activating Light Mode sets the `data-theme="light"` attribute on the `<body>` element. All colors are mapped to variables or specific class declarations using high-specificity selectors under this parent query.

### Theme Toggle & Persistence
- **Database Field**: `admin.theme` (`VARCHAR(20) NOT NULL DEFAULT 'dark'`), persisted inside [Admin.php](../src/Entity/Admin.php).
- **Toggle Control**: Located in the topbar layout inside [base.html.twig](../templates/base.html.twig). Clicking it dynamically toggles `data-theme="light"` on the body element.
- **API Endpoint**: Toggles are posted to the POST route `/settings/theme-toggle` handled in [ProfileController.php](../src/Controller/ProfileController.php), persisting the preference across logouts and devices.

---

## 3. Styling Guidelines for Light Mode Overrides

Since the app was designed originally with a translucent dark-glass aesthetic, transitioning to a high-contrast premium Light Mode requires careful handling of hardcoded backgrounds and inline colors.

### A. Global Layouts & Variables
Defined inside [base.html.twig](../templates/base.html.twig):
- `--glass-bg`: Swaps from a dark semi-transparent blue to high-opacity solid white (`rgba(255, 255, 255, 0.95)`).
- `--glass-border`: Swaps from dark translucent grey (`rgba(255, 255, 255, 0.08)`) to a clear light border (`#cbd5e1`).
- `--text-main`: Changed to a readable dark slate (`#334155`).
- `--text-muted`: Mapped to a readable grey (`#64748b`).

### B. Inline Style Overrides (Critical)
Many legacy elements contain hardcoded inline color rules. These are overridden in the stylesheet using `[style*="..."]` selectors combined with `!important`:
- **Text Color Override**:
  ```css
  body[data-theme="light"] [style*="color:#fff"],
  body[data-theme="light"] [style*="color: #fff"],
  body[data-theme="light"] [style*="color:#ffffff"],
  body[data-theme="light"] [style*="color: #ffffff"] {
      color: var(--text-header) !important;
  }
  ```
- **Outbound Webhook Modal Grids Background**:
  Webhook trigger panels use hardcoded inline backgrounds. Overridden in [facebook_bot_manager/index.html.twig](../templates/facebook_bot_manager/index.html.twig) and [whatsapp_bot_manager/index.html.twig](../templates/whatsapp_bot_manager/index.html.twig):
  ```css
  body[data-theme="light"] #outbound-webhook-modal div[style*="background: rgba(0,0,0,0.15)"],
  body[data-theme="light"] #outbound-webhook-modal div[style*="background:rgba(0,0,0,0.15)"] {
      background: #f8fafc !important;
      border-color: #cbd5e1 !important;
  }
  body[data-theme="light"] #outbound-webhook-modal span[style*="color: #fff"],
  body[data-theme="light"] #outbound-webhook-modal span[style*="color:#fff"] {
      color: #1e293b !important;
  }
  ```

---

## 4. Component-Specific Style Blocks

### Shared Inbox
Custom rules inside [inbox.html.twig](../templates/chat/inbox.html.twig) structure a soft, non-flashy light layout:
- Left sidebar and detail column elements use a soft grey canvas background (`#f8fafc` / `#f1f5f9`).
- Active search panels and contact filters map to clean borders and solid backgrounds.
- Inbound message bubbles render with grey backgrounds (`#f1f5f9`) and dark slate text, while outgoing templates match the platform primary/accent colors.

### Flow & Sequence Builders
Overridden in [empty.html.twig](../templates/empty.html.twig) (supporting both WhatsApp and Facebook builders):
- **Canvas Dot Grid**: Changed from a dark pattern to a clean light grey grid (`#f8fafc` with subtle dots).
- **Context Menus & Minimap**: Overridden to display light backgrounds, soft box shadows, and slate-colored borders to ensure context list options are fully readable.
- **Action Controls**: Minimap viewport buttons, control switches, and card handles utilize custom white states.

---

## 5. Best Practices for Developers

1. **Avoid Hardcoded Colors**: Use variables (e.g. `var(--text-main)`, `var(--glass-border)`) rather than fixed hex codes (`#fff`, `rgba(0,0,0,0.1)`).
2. **Handle Inline Style Precedence**: If you must write inline styles, ensure they support light mode using dynamic target overrides in stylesheets.
3. **Use Box-Sizing**: Ensure search inputs and textareas have `box-sizing: border-box !important` to prevent sidebars and panel outlines from overflowing their container grids.
