# RJM CSS Advisor

> AI-powered Custom CSS code generation for every ACF component. Describe your styling goal and the plugin writes the exact CSS for you.

---

## What It Does

When editing a page in WordPress, every **Custom CSS** field now has a **"Generate Custom CSS ✨"** button. Clicking it:

1. Opens a goal-entry form below the CSS field.
2. You type a plain-English description of what you want — e.g. *"Make the heading navy blue and 2rem, add extra padding on mobile"*.
3. Click **"Generate CSS ✨"** — the plugin fetches the **live component source code** from GitHub and submits it to your AI provider (GitHub Copilot or OpenAI).
4. The AI returns **ready-to-paste CSS code** using the exact class names from that component.
5. Click **↑ Insert into field** to paste the CSS directly into the field, or **Copy** to copy it to your clipboard.
6. If you want to adjust the output, click **↻ Try again** to go back to the goal form (your previous text is preserved) and regenerate.

---

## Installation

1. Download the latest release zip from [Releases](https://github.com/RJM-Digital/rjm-css-advisor/releases).
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**, upload the zip, and activate **RJM CSS Advisor**.
3. Go to **Settings → RJM CSS Advisor** and enter your credentials (see below).

---

## Configuration (Settings → RJM CSS Advisor)

All settings are entered through the WordPress admin interface. **Nothing needs to be added to `wp-config.php`.**

### GitHub Fine-Grained PAT

You need a **GitHub Fine-Grained Personal Access Token** that:

- Has **Contents: Read-only** access on the repository containing your ACF component source (e.g. `RJM-Digital/import-template-coach`).
- Belongs to a GitHub account that has a **Copilot Business seat** in the RJM Digital organisation.

#### How to create the token:

1. Go to **GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens**.
2. Click **Generate new token**.
3. Set:
   - **Token name:** `RJM CSS Advisor – [site name]`
   - **Expiration:** 90 days (recommended — set a calendar reminder to renew)
   - **Repository access:** Only select the component source repo
   - **Permissions → Repository permissions → Contents:** Read-only
4. Click **Generate token** and paste it into the plugin settings.

> ⚠️ **The token is stored encrypted** using your site's WordPress `AUTH_KEY`. It is never logged or transmitted anywhere except to GitHub and Copilot/OpenAI APIs.

---

## Releases & Auto-Updates

This plugin ships with a bundled update checker ([plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)) that polls this repo's GitHub Releases. WordPress sites with the plugin installed will see updates in **Plugins → Installed Plugins** automatically — no WordPress.org listing required.

### Cutting a release

1. Bump the `Version:` header (and `RJM_CSS_ADVISOR_VERSION` constant) in `rjm-css-advisor.php`.
2. Commit and push to `main`.
3. Tag and push the tag:
   ```
   git tag v1.0.28
   git push origin v1.0.28
   ```
4. The `.github/workflows/release.yml` workflow builds the zip and publishes a GitHub Release automatically.
5. Sites will pick up the update on their next update check (WP default cache is ~12 hours).

### Building the zip locally

```
bash scripts/package-plugin.sh
```
Outputs to `dist/rjm-css-advisor-{version}.zip`.
