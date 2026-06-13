# Publishing to the Craft CMS Plugin Store

## 1. Choose a License

**Commercial plugin:**
- Copy the [Craft license](https://craftcms.github.io/license/) text into `LICENSE.md` at the repo root
- Replace `[YOUR_NAME_HERE]` with your name or organization
- Set in `composer.json`: `"license": "proprietary"`

**Open source plugin:**
- Choose MIT, Apache-2.0, GPL-2.0, or GPL-3.0
- Save the license text to `LICENSE.txt`
- Set in `composer.json`: `"license": "MIT"` (or whichever applies)

---

## 2. Prepare `composer.json`

Declare the minimum Craft version your plugin supports:

```json
{
  "name": "vendor/plugin-handle",
  "type": "craft-plugin",
  "require": {
    "craftcms/cms": "^5.0.0"
  },
  "extra": {
    "handle": "your-plugin-handle",
    "name": "Your Plugin Name",
    "version": "1.0.0"
  }
}
```

For multi-version support (Craft 4 + 5):
```json
"craftcms/cms": "^4.8.1||^5.0.0"
```

---

## 3. Push to a Public GitHub Repository

- The repo must be **public**
- The root of the repo should contain `composer.json`, `LICENSE.md` (or `.txt`), and a `CHANGELOG.md`

---

## 4. Set Up a Craft Console Account

1. Go to [console.craftcms.com](https://console.craftcms.com) and create an account
2. Connect your **GitHub account** under account settings
3. Create an **organization** — this represents you or your business in the store and determines payout eligibility

---

## 5. Register Your Plugin

1. In your Console org, go to **Plugin Store → Plugins → Add a plugin**
2. Search for your GitHub repo and select it
3. Craft pre-fills details from your `composer.json` — review and update:
   - Display name
   - Short description
   - Screenshots
   - Category
   - Compatibility (Craft versions)

---

## 6. Set Pricing (Commercial Only)

**Initial price guidance:**

| Price | Use Case |
|-------|----------|
| $10–$29 | Lightweight utilities |
| $49–$99 | Complex field types |
| $149–$249 | Significant functionality |
| $499–$999 | Major applications |

**Renewal price:** Set at 20–50% of the initial price (e.g., $99 → $19–49/yr renewal).

**Important:** Pixel & Tonic takes a **20% processing fee** on all sales.

> **Critical gotcha:** If you submit your plugin as free, you cannot convert it to a commercial plugin later. You _can_ add paid editions with extended functionality on top of a free core.

---

## 7. Submit for Approval

1. Click **"Submit for approval"** in Craft Console
2. The Craft team reviews the submission
3. Once approved, your plugin becomes visible on [plugins.craftcms.com](https://plugins.craftcms.com)

---

## 8. Prepare `CHANGELOG.md`

Use this heading format — it's required for the Plugin Store to parse your releases:

```md
## 1.0.0 - 2026-06-01

### Added
- Initial release
```

---

## 9. Tag a Release

> **Required** for your plugin to appear inside Craft's control panel Plugin Store (not just the website listing).

1. Update `CHANGELOG.md` with the version heading and date
2. Update the `version` in `composer.json` if you have it hardcoded
3. Commit the changes
4. Create and push a Git tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Accepted tag formats: `v1.0.0`, `v1.0.0-beta.1`, `v1.0.0-RC1`, `release-1.0.0`

The Plugin Store picks up new tags automatically within **1–2 minutes**.

---

## 10. (Optional) Automate GitHub Releases

Add a GitHub Actions workflow at `.github/workflows/create-release.yml` to auto-generate GitHub Releases when the Plugin Store detects a new version tag.

---

## 11. (Optional) Register on Packagist

Register your plugin at [packagist.org](https://packagist.org) so developers can install and update it via Composer without going through the Plugin Store UI.

---

## Payout Setup

| Region | Payout Method |
|--------|--------------|
| US, Europe, AU, NZ | Stripe (automatic) |
| All other regions | PayPal |

If your situation is non-standard, email `support@craftcms.com` before publishing.

---

## Special Scenarios

- **Moving the GitHub repo URL:** Contact `support@craftcms.com` _before_ pushing new tags — the store won't recognize them at the new URL otherwise.
- **Changing the package name:** Tag releases for each supported Craft major version, submit as a new Packagist package, mark the old one as abandoned, and notify support.
- **Transferring ownership:** Transfer the repo on GitHub (Settings → Danger Zone), have the new owner update the package name, and contact support.
