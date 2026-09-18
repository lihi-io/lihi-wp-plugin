=== lihi Short URL ===
Contributors: lihidev
Tags: short url, url shortener, lihi, admin, media
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds lihi Short URL controls to create, copy, and edit short URLs for posts, pages, media, and public post types in the WordPress admin.

== Description ==

`lihi Short URL` integrates the [lihi](https://lihi.io) short-link service into the WordPress admin. Editors can create short URLs from post and media list screens, choose a redirect domain, add tags, and add UTM parameters for non-media items, then copy the result without leaving WordPress. Existing short URLs become Copy controls, and administrators can open the matching lihi dashboard page to edit the link. This plugin is open source and maintained at [lihi-io/lihi-wp-plugin](https://github.com/lihi-io/lihi-wp-plugin).

The plugin runs only inside `wp-admin`; it adds no front-end output and enqueues no scripts on public pages.

= Features =

* Adds a **lihi Short URL** column with a **Create** button to all public post-type list screens (posts, pages, custom post types).
* Adds the same Create / Copy controls to the Media Library list view and to the attachment detail panel in the media grid view.
* One-click copy: generates the short URL on demand via AJAX and writes it to the clipboard, with a manual-copy prompt if browser clipboard access is blocked.
* Creation modal: choose a redirect domain, add recommended or custom tags, and add UTM parameters for non-media items before creating a new short URL.
* UTM source and medium are loaded from the lihi account options, while campaign, term, and content remain free-text fields; media items hide UTM controls and submit blank UTM values.
* Reuses an existing short URL whenever one already exists for the item, so repeated clicks are idempotent.
* Copy buttons still confirm the upstream short URL exists before copying; if it was removed, the button returns to **Create** and opens the creation modal again.
* Marks items with `lihi_already = 1` post meta after a successful short-URL lookup/create; the frontend renders those buttons as "Copy".
* Administrators can open existing short URLs, personal domain management, and UTM option management in the lihi dashboard through a browser-proof passthrough flow.
* Settings page under **Settings → lihi Short URL** with Login and Register tabs, connected-account details, work-group switching, Logout, and a lihi dashboard shortcut. Login is selected by default, and the service heading breaks cleanly between its two sentences. Directly sanitized query-backed tab links and native WordPress AJAX POST forms keep tab switching and submission usable without JavaScript; JavaScript adds instant keyboard tabs, an accessible work-group modal layered above the dashboard workspace, inline errors, and delayed success feedback. Successful work-group changes reload the settings page after the confirmation so all group-scoped state is fresh. Logout makes one short best-effort server-session revocation and always continues with local credential cleanup.
* Registration checks the request country before sending a verification email. Allowed registrations retain the registration request's IP and device metadata for account creation; clicking the verification link does not replace them. After verifying, the administrator returns to the separate Login form; registration never logs the account in.
* Login uses server-side PKCE and stores the email, opaque server-issued session identifier, access token, and rotating refresh token together in one non-autoloaded WordPress option. A renewable 20-second database lease serializes Login and Refresh; only the current byte-exact lease owner can write credentials under the captured activation generation.
* Every refreshable protected lihi API request can refresh a rejected access token and retry once. Login and Refresh recheck activation after each remote response before renewing the lease; concurrent requests wait up to 18 seconds for a 15-second HTTP operation to finish. Any attempted refresh failure removes credentials still belonging to that Login session and asks the administrator to sign in again, while a newer Login remains untouched.
* Localised; ships with Traditional Chinese (`zh_TW`).

== External services ==

This plugin connects to the lihi short URL service to identify the WordPress site, authenticate the site administrator, and create or look up short URLs. Without an internet connection the plugin cannot function.

**Service: lihi WordPress API auth endpoints** (https://app.lihi.io/api/wordpress/v1/auth)

* When data is sent: when an administrator submits Register, Login, or Logout on **Settings → lihi Short URL**; when Login exchanges its short-lived authorization code; when any protected API rejects the access token and the plugin attempts one token refresh; and once during plugin uninstall when a local session is available.
* What is sent for Register: the entered email and password plus the WordPress site's hostname. The request also carries normal network metadata such as source IP and User-Agent, which lihi uses to check registration availability and record the registration country and device. Account-creation consent is checked locally before the request. A successful registration only sends a verification email; it returns no login credentials and does not connect the plugin.
* What is sent for Login: the entered email and password, the WordPress site's hostname, and a PKCE challenge generated by WordPress PHP. The server returns a short-lived authorization code; WordPress sends that code with the matching verifier retained only in PHP memory to exchange it for a server-issued UUID, access token, and refresh token.
* What is sent for refresh: the stored server-issued UUID and current refresh token. A successful refresh returns a new access token and a rotated refresh token.
* What is sent for Logout or uninstall: the stored access token in the Authorization header. No request body is sent. This one-shot request is not refreshed or retried; network or API failure is ignored so local Logout/uninstall cleanup still finishes.
* What WordPress stores: one non-autoloaded credential option containing the email, UUID, access token, and raw refresh token. The password, PKCE verifier, challenge, and authorization code are not stored after the request.

**Service: lihi WordPress API protected endpoints** (https://app.lihi.io/api/wordpress/v1)

* When data is sent: when a connected administrator opens the settings page to display account information, opens the work-group switcher, or confirms a work-group change; when the Create modal loads redirect-domain and UTM options; when an administrator opens the lihi dashboard through passthrough; and when a user clicks "Create", "Copy", or "Edit" to generate, look up, copy, or edit a short URL. Media Create modals hide UTM controls and submit blank UTM values.
* What is sent: the stored access token; when switching work groups, the selected numeric group ID or `null` for the personal work group; the post or attachment URL (`permalink` or attachment file URL, with entered UTM parameters appended); the post type namespace including the WordPress hostname; the post ID; the selected redirect domain; selected tags as a comma-separated string; and, when requesting browser passthrough, a browser-generated PKCE challenge plus an optional target such as a short URL or lihi dashboard path.

**Service: browser-facing lihi pages** (https://lihi.io, https://app.lihi.io, and https://lihidomain.com)

* When data is sent: only after a user clicks the lihi dashboard, password-reset, personal-domain, or verification-email link.
* What is sent: the browser's normal request metadata. Connected dashboard links additionally carry the short-lived passthrough nonce and browser verifier described above; verification links carry the one-time registration token from the email. Public home, password-reset, and public domain-information links receive no account credentials from the plugin.

By using the plugin you agree that the data above is transmitted to the lihi service. Please review the lihi service's legal documents:

* Terms of Use: https://knowledge.lihi.io/terms/
* Privacy Policy: https://knowledge.lihi.io/privacy-policy/

== Installation ==

1. Upload the `lihi-short-url` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress **Plugins** screen.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Settings → lihi Short URL**.
4. If you already have a verified lihi account, enter its email and password in **Login**. If you need an account, use the separate **Register** form, confirm account creation, open the verification email, then return and log in.
5. Once Login has stored a complete credential bundle, the **Create** button appears in a **lihi Short URL** column on every public post-type list screen and in the Media Library.
6. To use another available lihi work group, click **Switch** beside the current work group, choose it in the modal, and click **Switch work group**.

The plugin requires the `manage_options` capability to view or change settings. Any logged-in user can use the **Create** button on screens they are otherwise allowed to access.

Serve `wp-admin` over HTTPS before using Login or Register. Passwords first travel from the browser to same-origin WordPress AJAX, then WordPress calls lihi over HTTPS. The plugin does not hard-block non-HTTPS requests because TLS may terminate at a correctly configured reverse proxy.

== Frequently Asked Questions ==

= Why don't I see the Create button in my list tables? =

The UI hooks register only after Login has stored a complete email, UUID, access-token, and refresh-token bundle. Open **Settings → lihi Short URL** and log in with a verified account. Registering or clicking the verification link alone does not log in.

= How do I connect a different account? =

Click **Log out** to remove the local credential bundle, then use Login with the other account. There is no separately editable saved-email field.

= Can I manage redirect domains or UTM options from WordPress? =

Yes. Administrators can open lihi personal-domain and UTM option management from the Create modal. The plugin asks for confirmation, creates a short-lived passthrough nonce, then opens the lihi dashboard in a new tab.

= How do I disable the plugin without deactivating it? =

Click **Log out** on **Settings → lihi Short URL**. This removes the local credential bundle and stops registering the Short URL UI. Deactivation performs one complete transition: disable the generation before waiting, acquire the shared lock, repeat disable and credential purge under that lock, then release. If transition cleanup fails, isolated best-effort cleanup keeps authentication fail-closed and never directly deletes another request's lock.

= Does the plugin run on the front-end? =

No. The plugin returns early on non-admin requests — it only adds admin UI and an `admin-ajax.php` handler.

= Which post types are supported? =

All post types registered with `public => true`, plus the Media Library (both list mode and the grid view's attachment details panel).

= Does the plugin store data in my database? =

Yes — one non-autoloaded `lihi_auth_tokens` option containing `{ email, uuid, access_token, refresh_token }`, a non-autoloaded renewable 20-second `lihi_auth_tokens_lock`, one non-autoloaded random `lihi_auth_epoch`, and per-item `lihi_already` post meta. Credential writes atomically require the exact epoch and lease owner; cleanup uses a byte-exact raw stored value. Normal credential probes are memoized for the PHP request, while Login/Refresh concurrency checks always read fresh database state. Store failures during bootstrap probes degrade to disconnected instead of breaking wp-admin. The lihi password is never stored. Logging out removes the credential bundle. Deactivating or deleting the plugin removes its authentication options; if another request still owns the lease, cleanup disables authentication without force-deleting that foreign lock. Per-item `lihi_already` post meta remains attached to its item.

== Changelog ==

= 1.0.7 =
* Verifies compatibility with WordPress 7.1.1 on PHP 7.4 and PHP 8.2.
* Updates the production app/API host used by API requests, dashboard passthrough, and password-reset links to app.lihi.io.
* Fixes the UTM management shortcut to open the lihi dashboard UTM page through authenticated passthrough.

= 1.0.6 =
* Adds separate Login and Register forms; Register sends verification only and never logs in.
* Adds server-side PKCE authorization-code exchange, one atomic email/UUID/access/refresh credential bundle, rotating refresh tokens, and one access fallback retry for every refreshable protected API.
* Adds one renewable 20-second database-backed auth lease around complete Login and Refresh flows plus local Logout, with owner-only renewal between remote steps and 18-second waiter budgets.
* Revokes the server-issued WordPress client on Logout and uninstall with a one-shot five-second request. Uninstall fences and drains Login/Refresh first, then performs revocation and local purge under the same lifecycle lease; every remote failure remains best-effort so cleanup always continues.
* Adds activation-generation fencing, response-time epoch rechecks, and lock-aware lifecycle cleanup so stale requests cannot restore credentials after deactivation or rapid reactivation.
* Keeps Login and Register usable as native WordPress AJAX POST forms without JavaScript, with Unicode-consistent password validation and accessible inline errors when JavaScript is available.
* Presents Login and Register as mutually exclusive tabs with Login selected by default, and adds an accessible modal for loading and switching lihi work groups.
* Replaces profile end dates with nullable work-group names, validates profile field types before rendering, and updates the protected API contract to use `/user/domain-options`, `/user/group-options`, and `/user/switch-group`.
* Sends the WordPress hostname with Login, reports unavailable registration countries with translated plugin copy before mail is sent, and keeps registration-time country, IP, and device metadata through email verification.
* Gives `site/store` `need_upgrade` failures a dedicated translated upgrade-or-renew message while keeping `site_create_fail` on the normal validation-error path, and classifies every HTTP 5xx response as a service failure before inspecting identity markers.
* Hardens concurrent authentication storage with request-memoized helper reads, fresh concurrency reads, guarded atomic credential upserts, `CAST(... AS BINARY)` raw-value cleanup, consistent database error handling, malformed-row repair, scoped non-autoloaded option-cache invalidation without evicting `alloptions`, and fail-closed bootstrap/lifecycle behavior.
* Sanitizes the read-only authentication-tab query directly, improves the service-heading sentence break, keeps the work-group modal above later dashboard content, and reloads the settings page after successful work-group switches.

= 1.0.5 =
* Fixes Plugin Check security findings around admin AJAX request parsing and escaped output.

= 1.0.4 =
* Adds JavaScript-rendered Create, Copy, and administrator-only Edit controls for the lihi Short URL column.
* Adds a creation modal with redirect-domain selection, click-to-add recommended tags, custom tags, UTM source / medium options, and free-text UTM fields for non-media items.
* Verifies existing short URLs before Copy and Edit; removed upstream links reset the item back to Create and reopen the creation flow.
* Adds clipboard-blocked fallback prompts so generated short URLs remain available for manual copy.
* Adds lihi dashboard passthrough for editing short URLs, managing personal domains, and managing UTM options.
* Updates the settings page with password-based email verification, account-creation consent, connected-account details, and a lihi dashboard service overview.
* Aligns the lihi WordPress API client with the current `site/find` single-result response and `user/options` domain / UTM options response.

= 1.0.3 =
* Unifies authentication and short-URL calls under the lihi WordPress API client.
* Updates internal dependency composition for client, service, and store singletons.
* Clears saved settings, site UUID, and cached token when the plugin is deactivated.

= 1.0.2 =
* Strengthens authentication identity checks by sending the site hostname and persistent site UUID in the authentication JSON payload instead of relying on the HTTP Host header.
* Sends WordPress' mobile-request flag (`is_mobile`) on auth login requests.

= 1.0.1 =
* Aligns the plugin package directory, main file, and text domain with the WordPress.org slug.
* Removes dashboard-wide setup notices while keeping the settings page available.
* Updates release packaging validation for the `lihi-short-url` directory.

= 1.0.0 =
* Initial release.
* Adds a "lihi" short-URL button to all public post-type list tables and the Media Library.
* Settings page with email verification flow and per-account redirect domain selection.
* Traditional Chinese (`zh_TW`) translation included.

== Upgrade Notice ==

= 1.0.7 =
Updates the production app/API host to app.lihi.io and fixes the UTM management shortcut destination; no action required.

= 1.0.6 =
Adds PKCE Login, rotating access / refresh credentials, work-group switching, and stricter API failure handling. Existing saved credentials are not migrated; log in again after updating.

= 1.0.5 =
Fixes Plugin Check security findings for admin AJAX parsing and output escaping; no action required.

= 1.0.4 =
Adds modal short-URL creation options, JS-rendered Copy/Edit states, lihi dashboard passthrough, password-based account verification, and current lihi API option handling; no action required.

= 1.0.3 =
Unifies the lihi API client internals and clears saved plugin data on deactivation; no action required.

= 1.0.2 =
Strengthens authentication site identity verification; no action required.

= 1.0.1 =
Updates WordPress.org release metadata, package paths, and authentication site identity payload; no action required.

= 1.0.0 =
Initial release.
