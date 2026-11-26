=== formexposed – Contact form spam check ===
Contributors: lwitsolutions
Tags: contact form, spam, bots, captcha, honeypot
Requires at least: 6.2
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.10.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks whether your contact forms are protected against bots and spam, adds a free protection layer and connects hosted form protection.

== Description ==

Most contact form spam is not a mystery: the form has no protection a bot would notice. formexposed shows you what is missing and closes the gap - with a free protection layer that runs on your own server, and optionally with the hosted form protection of formexposed.

**Check your forms (free).** One click asks formexposed.com to look at your site the way a bot would: it loads a few public pages, finds the contact forms and checks for CAPTCHA, honeypot fields, tokens, rate limits and time checks. Settings → formexposed shows the verdict - *Exposed*, *Partially protected* or *Protected* - with a risk score, the list of what is missing, a fix guide and a link to the full report. The check is passive: nothing is ever submitted through your forms.

**Protection layer (free, runs on your server).** One click adds a hidden trap field, a signed minimum fill time, proof that a person used the page, a rate limit and header-injection and mail-domain checks to the forms of Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable and Ninja Forms, to comments and to registration (WordPress and WooCommerce). Optional: the WooCommerce classic checkout and the login form. No keys, no third-party service.

* **Invisible proof of work** instead of a CAPTCHA: the visitor's browser solves a small calculation (well under a second, even on a phone). No puzzle, no cookie, nothing to click for screen reader users.
* **Filters you choose:** throw-away e-mail addresses (on by default), messages in a writing system you never get real enquiries in, your own blocked words, countries (only if Cloudflare or a GeoIP module of your server knows the country - no lookup leaves your site). New rules can be observed for seven days before they apply.
* **Nothing is lost:** every stopped message waits 14 days in a quarantine on your server, with the reason in plain words. One click delivers it.
* **Move away from Google reCAPTCHA in one click** (Contact Form 7, WPForms), with a self-test, a backup and Undo.

**Hosted form protection (optional, needs a formexposed account).** Settings → formexposed → Form protection connects Contact Form 7, WPForms, Gravity Forms, Elementor Pro forms, Fluent Forms, Formidable Forms, comments, the WordPress and WooCommerce registration and - if you want - the login form to the form protection service of formexposed (formconfirmed): an invisible proof of work, timing and trap-field signals, address reputation, an e-mail check and an optional check of the message text, on servers in the EU, without cookies. Every token is bound to the form type it was made for. Keys are typed in or fetched from a connected account with one click; a "Test the connection" button tells you at once whether everything works.

**No silent pass when the check fails.** If the service cannot be reached, answers with an error, or only the visitor's browser claims it could not reach the service, a message is *not* waved through. It is delivered only if an independent check on your own site passed - the protection layer with the proof of work. Otherwise it is held for review in the quarantine (the default) or refused, as you choose. A message the service flags as suspicious is never delivered unseen either. Logins are the one exception: there the password is the independent check.

**Monitoring and early warnings (optional, with a free formexposed account).** One click connects the site - no DNS record, no file upload. Then your forms are checked on a schedule, you get a message when protection drops, when Google's reCAPTCHA stops protecting (above its free quota Google lets every request pass), when a form that used to get messages suddenly gets none, and - after a plugin, theme or WordPress update - the forms are checked again and the update is named if protection is gone. Swarm protection blocks link domains that appear in spam on several connected sites.

**Rescue net for enquiries.** When the e-mail a form sends to you cannot be handed over (wrong SMTP login, mail server down), the enquiry is usually lost. formexposed keeps a copy of exactly that e-mail on your server for 30 days and sends it again with one click once mail works.

**Form settings check and storage limit.** Finds settings that cost enquiries or expose data (the visitor's address as sender, no notification, a free mail account as recipient, file fields without a type limit, upload folders that list their files, auto-replies spammers abuse) and deletes stored enquiries after 90, 180 or 365 days if you want (Flamingo, Gravity Forms, Ninja Forms).

**For agencies.** Connect many client sites at once with a rollout code - `wp formexposed connect fxc_...` - and see them in one portfolio.

== Installation ==

1. Install the plugin from the plugin directory (or upload the folder to `/wp-content/plugins/`) and activate it.
2. Open **Settings → formexposed** and click **Check now**.
3. To protect your forms on your own server, click **Fix it for me** on the same page. For hosted form protection, open the tab **Form protection**, enter the keys from your formexposed account (or fetch them with one click) and click **Test the connection**.

== Frequently Asked Questions ==

= Does the check submit my contact form? =

No. The check only reads public pages. It never fills or submits a form.

= Why does my page contain a formexposed-verify meta tag? =

It proves that a check request really comes from your site. It contains a random key derived from a secret of the plugin, nothing personal, and it is removed when you uninstall the plugin.

= What happens when the hosted form protection cannot be reached? =

The message is not let through unchecked. If the protection layer of this plugin with the proof of work is on and the message passed it, the message is delivered - that layer is an independent second check on your own server. Otherwise the message is held in the quarantine for review (Contact Form 7 and WPForms show the visitor the normal confirmation, comments wait for moderation, Gravity Forms files the entry under Spam) or refused with "please try again", depending on Settings → formexposed → Form protection → "Not a clear pass". Elementor, Fluent Forms and Formidable cannot hold a message back; there the visitor sees the error and a copy waits in the quarantine. Registrations are refused. The same applies when the service reports an error that means no check took place (wrong secret key, site paused, service switched off) and when a visitor's browser reports that it could not reach the service. While this happens, the dashboard shows a notice. Messages the service flags as suspicious are treated the same way; only "allow" delivers directly.

= Is the protection layer a real "independent check"? =

It runs entirely on your server, with its own signed tokens and its own proof of work, and does not depend on the service. It is only accepted as the fallback if the proof of work is switched on and was actually verified for that submission. Without it there is no fallback, and unclear messages are held or refused.

= Which data does the hosted form protection receive? =

On submission: the token from the widget, the visitor's IP address, the value of the hidden trap field, the e-mail address entered in the form, the form type (contact, comment, register, login) and - if you allow it - the message text (at most 2,000 characters; never for logins). The service checks the text in memory and does not store it; it keeps the IP address only shortened and hashed. See "External services" below.

= What does the plugin store? =

Options in your database, all starting with `formexposed_`, and two tables: the quarantine (14 days) and the rescued enquiries (30 days). Uninstalling removes all of them, the tables, the transients and the scheduled events, on every site of a multisite network.

= How does the proof of work work? =

It is part of the protection layer and off until you switch it on. On the first interaction with a form the page fetches a challenge from your own site (admin-ajax.php), searches a number up to 50,000 with SHA-256 and puts the answer into a hidden field; a submit that comes earlier waits. Measured on a test site: about 0.1 s on a desktop and at most about 0.4 s with a six times slower processor. Switching it on or off empties the page caches of the common caching plugins; if a CDN caches your pages, clear it yourself.

= What does "Move away from reCAPTCHA" change? =

It runs a self-test of the proof of work first (including a request to admin-ajax.php the way a visitor makes it). Only if that passes it switches the proof of work on, keeps a backup of the reCAPTCHA settings, removes the reCAPTCHA keys from Contact Form 7 and sets the CAPTCHA provider of WPForms to "none". Gravity Forms and Elementor forms are never edited; the page lists the field to remove. "Undo" puts the backup back.

= My site is behind a reverse proxy or CDN. =

The rate limit of the protection layer and the IP address sent to the hosted form protection use the visitor address. Behind a proxy every visitor may arrive with the proxy's address. Return the real visitor address with the filter `formexposed_client_ip`; only a valid IP address is used.

= Which maintenance plugins are recognised? =

WP Maintenance Mode / LightStart, SeedProd, Maintenance (WebFactory), Under Construction, CMP – Coming Soon & Maintenance and the maintenance mode of Elementor. Other setups can report through the filter `formexposed_maintenance`. While the site is in maintenance, formexposed keeps your alerts quiet.

= What are the optional form tools? =

All off by default. A visual check recorder for site administrators (records up to five entry clicks for a browser check, never input values or submissions); seven-day observation of changed filter rules; visitor recovery guidance after an offline submission or a Contact Form 7 mail failure (no drafts, no automatic resend); and form counters (views, starts, validation errors, submit attempts per form, optionally a field position and a validation category - never field names or values). Counts are kept 14 days on your site and, while connected, sent daily as numbers.

= My agency looks after my site. What do I do? =

Nothing, if the agency installed the plugin. Otherwise enter the code the agency gave you in Settings → formexposed → "Looked after by an agency?".

== Screenshots ==

1. The verdict of the check with the list of what is missing and the protection layer.
2. Hosted form protection: status, fallback check, keys, protected forms and what happens when the check is not a clear pass.
3. The quarantine: stopped and held messages with the reason, delivered with one click.
4. The form settings check with the form names and how to fix each point.

== External services ==

This plugin talks to two services of LW IT Solutions (formexposed): **formexposed.com** (form check, monitoring, account connection) and, only when you enter keys for the hosted form protection, **formconfirmed.com** (form protection). Each service is described below with the data sent and when.

formexposed.com: terms of service https://formexposed.com/en/legal/terms · privacy policy https://formexposed.com/en/legal/privacy · data processing agreement https://formexposed.com/en/legal/dpa · legal notice https://formexposed.com/en/legal/imprint

formconfirmed.com: terms of service https://formconfirmed.com/en/legal/terms · privacy policy https://formconfirmed.com/en/legal/privacy

= formexposed.com - the check =

Only when an administrator clicks "Check now" (or "Check again" / "Try again" after an earlier check): one request, and while that check runs one status request every few seconds from the admin page. Nothing else starts a check: activating the plugin, switching the protection layer on or off, removing a reCAPTCHA test key and all other settings send nothing to formexposed.com. Sent: your site's host name, the plugin version, the WordPress version and the site language. To prove the request comes from this site, the plugin prints a `<meta name="formexposed-verify">` tag with a random key in the head of your pages. The service then loads a few of your public pages with the user agent `FormExposedBot` (https://formexposed.com/bot). Site owners can opt out at https://formexposed.com/opt-out.

= formexposed.com - connecting an account =

When you click "Connect to a formexposed account", the plugin registers the site (POST /api/plugin/register: host name, plugin key, the plugin's connect secret, plugin and WordPress versions) and opens a one-time link (15 minutes, single use) in your browser. It then asks whether the connection was confirmed (POST /api/plugin/plan: host name, plugin key, connect secret) when the settings page is opened and once a day, for at most seven days. An agency code is sent once (POST /api/plugin/claim) with the same data. "Get keys from my formexposed account" on the Form protection tab sends the same three values (POST /api/plugin/protect) and receives the site key and secret key of the hosted form protection.

= formexposed.com - while the site is connected =

Nothing of this is sent before the site is connected (after "Connect" or an agency code):

* Once a day (POST /api/plugin/stats): the counts of the last 14 days - blocked submissions by reason, accepted submissions, sent and failed e-mails with the kind of the last failure, three numbers about Google reCAPTCHA answers, released quarantine messages; with swarm protection on (default) the domain names of links in stopped messages; the slug and version of active plugins that are on a fixed watch list of 41 form, CAPTCHA and mail plugins (`includes/class-formexposed-health.php`; the service can only shorten it); numbers from the form settings check; the storage limit and counts of stored enquiries; with the optional form counters, their counts. Always with host name, plugin key, connect secret and plugin version. Never e-mail addresses, names or message content. The answer contains the swarm list (domain names).
* Within a minute of a plugin, theme or WordPress update, activation or deactivation (POST /api/plugin/changes): the name and the old and new version.
* When a form's e-mail fails (POST /api/plugin/mailfail, at most once an hour): the kind of the error and whether the enquiry was kept.
* While the site is in maintenance mode (POST /api/plugin/maintenance): "on" every hour, "off" once.

= formexposed.com - check recorder (optional) =

Nothing happens until a site administrator starts a recording in the formexposed.com account of the site. formexposed.com then opens a page of your site in a new window; while a logged-in administrator clicks the steps to a form, the page records up to five clicks as CSS selectors. When the form is selected, the page hands back to the formexposed.com window (in the browser, with window.postMessage): the address of the page and of the entry page (without query string), the CSS selectors of the recorded clicks and of the form, and a one-time code of the recording. Never input values, form contents or submissions. Nothing is sent when no recording is started.

= formconfirmed.com - hosted form protection =

This service is run by LW IT Solutions as formconfirmed: terms of service https://formconfirmed.com/en/legal/terms, privacy policy https://formconfirmed.com/en/legal/privacy.

Only after you entered (or fetched) a site key and a secret key on the Form protection tab:

* On pages with a protected form, the visitor's browser loads the widget script `https://formconfirmed.com/v1/widget.js` and fetches a task from the service. The widget sets no cookies and stores nothing on the device; it sends the public site key, the form type and the page language.
* When the form is sent, your server asks the service (POST https://formconfirmed.com/v1/verify): the secret key, the token from the widget, the visitor's IP address, the value of the hidden trap field, the first e-mail address of the form, the form type, and - if "Message text" is on - the message text (at most 2,000 characters, never for logins). The service checks the text in memory and does not store it; the IP address is stored only shortened and hashed. The answer is allow, flag or block with reasons.
* "Test the connection" asks https://formconfirmed.com/v1/health and sends one verify request with the secret key and an empty token.

Not sent anywhere: the quarantine, the rescued e-mails, stored enquiries, form names and the details of the form settings check. The proof of work of the protection layer runs on this site only; its challenges come from your own server and are signed with a key derived from your WordPress salts.

== Changelog ==

= 1.10.3 =
* Every request value is sanitized as it is read (sanitize_text_field, sanitize_key, sanitize_email, FILTER_SANITIZE_URL, FILTER_SANITIZE_SPECIAL_CHARS) instead of being read raw and checked later.

= 1.10.2 =
* A check on formexposed.com runs only when you click "Check now" / "Check again". Switching the protection layer on and removing a reCAPTCHA test key no longer start a check by themselves.
* The notices "Your contact form is exposed" and "enquiries could not be sent" appear only on the dashboard, the plugins page and the formexposed page.

= 1.10.1 =
* Readme: the External services section names the terms of service and privacy policy of both services (formexposed.com and formconfirmed.com) with their direct addresses and describes the optional check recorder.

= 1.10.0 =
* Works with the new formconfirmed plugin: while it is active, the form protection tab stands down and formconfirmed checks the forms with the same keys. The protection layer keeps running and serves formconfirmed as its backup check; messages formconfirmed holds wait in the quarantine of this plugin.

= 1.9.0 =
* Hosted form protection follows the outage rule of the service: no silent pass. When the service cannot be reached, answers with an error, answers something unknown, or only the visitor's browser reports an outage, a message goes through only if the protection layer with the proof of work passed it; otherwise it is held for review in the quarantine (default) or refused. 1.8.0-1.8.2 let such messages through.
* Flagged messages are held for review or refused, never delivered unseen.
* New: Gravity Forms, Elementor Pro forms, Fluent Forms, Formidable Forms, WordPress registration, WooCommerce account registration and the login form (off by default) for the hosted form protection.
* Every token is bound to its form type (contact, comment, register, login).
* The widget script only loads on pages with a protected form.
* Form protection is now a tab of Settings → formexposed, with status, last problem in plain words, fallback status, "Test the connection" and a dashboard notice while messages are held.
* The proof-of-work answer is plain JSON. Code clean-up for the WordPress.org guidelines; the main class is now Formexposed_Core.
* Uninstall also removes the settings of the hosted form protection.

= 1.8.3 =
* Form protection: when a visitor's browser reports that it could not reach the service, the message is still stopped.

= 1.8.2 =
* Form protection: also sends the value of the hidden trap field and the e-mail address of the form. Nothing of it is stored.

= 1.8.1 =
* Form protection: problems on our side (protection switched off, site paused, wrong secret) no longer reject messages. (Replaced in 1.9.0: such messages are held, not let through.)

= 1.8.0 =
* New: hosted form protection for Contact Form 7, WPForms and comments.

= 1.7.0 =
* Admin-only visual recorder for form check journeys, seven-day observation of filter rules, optional visitor recovery guidance, optional field-position counters.

= 1.6.0 =
* Optional counters per form, off by default. Daily counts of released quarantine messages.

= 1.5.1 =
* Fix: WooCommerce orders that create an account were stopped by the registration protection.
* Fix: comments and registrations that do not come from the core forms are no longer checked.
* The protection layer is free for every site.

= 1.5.0 =
* Protection layer for Fluent Forms, Formidable Forms, Ninja Forms, comments, registration, WooCommerce classic checkout and login (the last two off by default). Filters for throw-away addresses, writing systems, blocked words and countries.

= 1.4.0 =
* Rescue net for failed form e-mails, invisible proof of work, one-click move away from reCAPTCHA, storage limit, form settings check, vulnerability watch, maintenance mode detection, one-time connect link.

= 1.3.0 =
* Quarantine, swarm protection, early warnings (reCAPTCHA quota, silent forms, updates).

= 1.2.0 =
* Protection layer for WPForms, Gravity Forms and Elementor Pro forms, daily counts for connected sites, agency rollout, WP-CLI.

= 1.1.0 =
* "Fix it for me" for Contact Form 7.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.9.0 =
Hosted form protection no longer lets messages through when the service cannot be reached or flags them; they are held for review unless the protection layer with the proof of work passed them. Please clear your page cache after the update.

= 1.5.1 =
The protection layer is now free for every site. Fixes WooCommerce orders that create an account being stopped.
