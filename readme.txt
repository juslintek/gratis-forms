=== Gratis Forms ===
Contributors: juslintek
Tags: forms, contact form, form builder, recaptcha, spam
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drag-and-drop form builder with conditional fields, anti-spam, and submission storage.

== Description ==

Drag-and-drop form builder with conditional fields, anti-spam, and submission storage.

Part of the Gratis suite — a collection of free, no-upsell WordPress plugins.

**Features:**

* Drag-and-drop builder with seven field types
* Honeypot and optional reCAPTCHA v3 anti-spam
* Submission storage in a dedicated table
* Admin notification email and CSV export

**Philosophy:**

Everything is free. No "Pro" version, no locked features, no nag screens. Optional managed cloud services may be offered separately to cover infrastructure costs.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Configure it from its settings screen in the WordPress admin.

== Frequently Asked Questions ==

= Is this plugin really free? =

Yes. Everything is free — no "Pro" version, no locked features, and no nag screens. Optional managed cloud services may be offered separately to cover infrastructure costs.

= Does this plugin make external HTTP requests? =

No, unless you explicitly configure Google reCAPTCHA v3 keys in the plugin settings.

= Where do I get support? =

Open an issue on the plugin's GitHub repository.

== Third-Party Services ==

This plugin optionally integrates with Google reCAPTCHA v3 for spam protection when you enter a Site Key and Secret Key in the settings.

= Google reCAPTCHA v3 =

When configured, the plugin loads the reCAPTCHA script (`https://www.google.com/recaptcha/api.js`) on pages containing a form and verifies submission tokens with `https://www.google.com/recaptcha/api/siteverify`.

* [Google Terms of Service](https://policies.google.com/terms)
* [Google Privacy Policy](https://policies.google.com/privacy)

No requests are sent to Google unless you configure reCAPTCHA keys.

== Changelog ==

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
