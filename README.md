# lihi WP Plugin

A WordPress admin plugin that integrates with the [lihi](https://lihi.io) URL shortener service. It adds a **lihi Short URL** column to all public post-type list tables and a **Create** button to the media attachment detail panel, letting editors generate and copy a lihi short URL with a single click.

## Features

- **lihi Short URL column** — appears on every public post type (posts, pages, custom post types), current post type only.
- **Media attachment support** — Create / Copy controls appear in the attachment detail panel of the media grid view.
- **Get-or-create** — fetches the existing short link for a post from lihi; creates one automatically if none exists.
- **One-click copy** — Create buttons open a modal for domain, extra tags, UTM source / medium choices, and remaining UTM fields before creating and copying; media / attachment items hide the UTM controls and submit blank UTM values. Copy-state buttons still call the lihi API to confirm the short URL exists before copying; if it was removed upstream, the item is reset to `lihi_already = 0`, the button returns to **Create**, and the create modal opens. If browser clipboard access is blocked after a successful API response, the short URL is shown in a prompt for manual copy.
- **Rotating authentication** — server-side PKCE login stores one site-scoped, non-autoloaded `{ email, uuid, access_token, refresh_token }` credential tuple. Every protected API call can refresh a rejected access token and retry once. One DB-backed auth lock serializes each complete remote Login and Refresh flow plus Logout; if an attempted refresh fails for any reason, credentials still belonging to that server-issued Login-session UUID are removed and Login is required again.
- **Lifecycle fencing** — every activation creates a new random, non-autoloaded auth generation. Credentials, generation state, and mutex ownership are always read directly from the options table, with explicit missing/empty markers and database errors that fail closed. A guarded credential upsert and lock-owned exact raw-value cleanup prevent stale Login or Refresh requests from overwriting or deleting replacement secrets.
- **Settings page** — Login and Register live under Settings → lihi Short URL as mutually exclusive tabs with Login selected by default. They are native POST forms with hidden action/nonces and directly sanitized query-backed tab links, so both submission and tab switching work without JavaScript; JavaScript adds instant keyboard tabs, inline AJAX feedback, ARIA-linked field errors, and a two-second success delay before Login/Logout reload. The service heading breaks cleanly between its two sentences. PHP and JavaScript both require six Unicode code points in the raw Register password without applying different whitespace rules. Registration sends a verification email and does not log in. lihi checks the registration request's country before sending mail; an unavailable country is reported with plugin-owned gettext copy. Login connects a verified account and shows its plan tier plus current work group; a modal loads available groups, stays layered above the dashboard workspace, and switches the WordPress client between groups. A successful switch shows its confirmation for two seconds, then reloads the settings page to fetch fresh group-scoped state. Logout removes the local credential tuple. Create / Copy controls appear only while that complete bundle is present.
- **i18n ready** — full Traditional Chinese (zh_TW) translation included; text domain `lihi-short-url`.

## Requirements

- WordPress 5.5+
- PHP 7.4+
- HTTPS for `wp-admin` in any shared or production environment, because Login and Register passwords first travel from the browser to same-origin WordPress AJAX before the server calls lihi over HTTPS. The plugin does not hard-block non-HTTPS requests so correctly configured TLS-terminating proxies remain supported.
- Docker & Docker Compose (for local development)
- `gettext` / `msgfmt` (for compiling translations)

## Local Development

### Start the stack

```bash
docker compose up -d
```

WordPress is available at **http://localhost:8080**.

The plugin directory (`lihi-short-url/`) is bind-mounted into the container at `wp-content/plugins/lihi-short-url`, so changes take effect immediately without rebuilding.

### Compile translations

```bash
make          # compile all .mo files from .po sources
make clean    # remove compiled .mo files
```

Translation files live in `lihi-short-url/languages/`.

## Testing

Tests use PHPUnit with Brain\Monkey to mock WordPress functions. `TokenStoreDatabaseTest` additionally exercises guarded upserts, raw binary CAS cleanup, and malformed-row recovery against the real WordPress MySQL options table. A dedicated Docker profile provides the test database plus separate PHP 7.4 and PHP 8.2 PHPUnit containers built from official `php:*-cli` images. Composer is run only inside those containers. The containers mount only `lihi-short-url/`, `tests/`, `patchwork.json`, and `phpunit.xml` read-only under `/app/code`; each service exposes its version-specific Composer file and lock as `/app/composer.json` and `/app/composer.lock`, while vendor dependencies and WordPress core installs live in that service's Docker-managed `/app` volume.

```bash
make test
```

`make test` stops the local WordPress / MySQL development services, runs PHP 7.4 and PHP 8.2 one at a time against `db_test`, then stops the PHPUnit service after each run so Docker does not keep both PHP containers alive at once.

To run one version manually:

```bash
docker compose stop wordpress db
docker compose --profile test stop phpunit82
docker compose --profile test up -d --build db_test phpunit74

docker compose --profile test exec phpunit74 composer install --working-dir=/app
docker compose --profile test exec phpunit74 sh -lc 'cd /app/code && /app/vendor/bin/phpunit -c phpunit.xml'
docker compose --profile test stop phpunit74

docker compose --profile test up -d --build db_test phpunit82
docker compose --profile test exec phpunit82 composer install --working-dir=/app
docker compose --profile test exec phpunit82 sh -lc 'cd /app/code && /app/vendor/bin/phpunit -c phpunit.xml'
docker compose --profile test stop phpunit82 db_test
```

The PHP 7.4 container covers the plugin's minimum supported PHP version. The PHP 8.2 container catches compatibility issues on a modern runtime.

The current suite contains **237 tests, 624 assertions** across the PHP 7.4 and PHP 8.2 runs.

`make coverage` follows the same one-version-at-a-time container pattern and writes reports under `/app/coverage` inside each matching container workspace.

For a single-version run, execute the matching service only:

```bash
make test74
```

## Packaging

GitHub Actions automatically builds the distributable plugin ZIP via `.github/workflows/package-plugin.yml` only when a tag is pushed.

Release metadata is kept in sync across the plugin header, WordPress.org readme, maintenance link, slug / text domain, and translations for the release package. Admin button assets use filemtime enqueue versions so JavaScript and CSS changes do not mix cached files across the split button stack.

The tag workflow uploads an artifact named `lihi-short-url-plugin` containing `build/lihi-short-url.zip`, then the release job downloads that same artifact and creates or updates the GitHub Release for the tag. The ZIP keeps the WordPress-required top-level `lihi-short-url/` directory and verifies that `lihi-short-url.php` and `readme.txt` are present before release.

## Architecture

```
lihi-short-url/
├── lihi-short-url.php          Plugin entry point; self-loading activation/deactivation lifecycle fence and lock-aware credential cleanup independent of admin bootstrap; admin-only guard; declares Text Domain lihi-short-url
├── readme.txt                 WordPress.org-format readme rendered on the plugin directory listing (WordPress.org metadata, External services disclosure, GitHub maintenance link, FAQ, Changelog)
├── LICENSE                    GPL-2.0-or-later license text
├── uninstall.php              Cleanup on plugin deletion: disables the auth epoch, lock-aware flushes credentials, and never directly removes a foreign lock
├── bootstrap.php              Loads class files unconditionally; settings/AJAX remain available while disconnected, while Short URL UI hooks self-guard on lihi_is_authenticated()
├── assets/
│   ├── lihi-button-api.js     Frontend Short URL API facade; encapsulates admin-ajax action/nonce payloads for options/create/copy/passthrough, JSON error parsing, and a 60-second domain / UTM options cache
│   ├── lihi-button-modal.js   Centered fade create / notice / confirm modal rendering and interaction; loads domain and UTM source / medium options, renders Domain custom-domain actions for managers and non-managers, renders UTM management actions for admins, click-to-add recommended tag buttons, removable selected chips inside the tag input, hides UTM fields for media / attachment items while submitting blank UTM values, and collects UTM fields for other item types
│   ├── lihi-button.js         Async delegated click handler; appends buttons into empty data-lihi-container nodes, frontend-renders button labels from data-lihi-already (Create vs Copy), renders an adjacent Edit button for ready rows only when the current user can manage options, flips newly successful buttons to Copy before clipboard writes, falls back to a manual-copy prompt when clipboard access is blocked, verifies Copy/Edit clicks against the API, opens lihi personal-domain / UTM settings pages through passthrough, and opens lihi-admin in a new tab with GET passthrough nonce URLs
│   ├── lihi-button.css        Admin button and modal styles; keeps list-table Create / Copy / Edit buttons aligned to the default WordPress admin button height
│   └── lihi-settings.js       Progressive enhancement for native Login/Register POST forms plus Logout; keyboard tabs, Unicode-aware validation, ARIA-linked field errors, work-group option/switch dialog, text-only feedback, two-second login/logout/work-group reload, and no token exposure to JavaScript
└── includes/
    ├── helper.php             Fail-closed option/context helpers: lihi_email() and lihi_is_authenticated() read the complete email/session-id/access/refresh bundle without letting bootstrap store errors escape
    ├── lihi-singletons.php    Lihi_Singletons registry/composition class; static lihi_client(), lihi_token_store(), lihi_service(), plus *_set() test helpers
    ├── settings.php           Query-backed Login/Register tabs over native admin-ajax POST forms; Login/Register/Logout, work-group options/switch, and dashboard passthrough handlers with capability checks and nonces
    ├── shorturl-column-ajax.php AJAX handlers for options/create/copy/passthrough; validates nonce/read_post/complete connection, requires manage_options for passthrough, sanitizes modal options, maps exceptions, and writes lihi_already state
    ├── add-shorturl-column.php Column registration guarded by lihi_is_authenticated(), empty wp_kses-escaped data-lihi-container mount points for frontend-rendered buttons, localized button config, and attachment detail panel field
    ├── client/
    │   ├── lihi-client-interface.php       Auth contract for login/register/authorization-code exchange/refresh plus protected profile, domain-option, work-group, passthrough, and short-URL endpoints; every protected method accepts an access fallback and retries itself at most once
    │   ├── lihi-client.php                 HTTP client; Login and Register send the trusted WordPress hostname, Login adds credentials + PKCE challenge, Register maps the stable country-unavailable reason, auth success requires HTTP 2xx plus result:true, token exchange receives the server UUID/access/refresh tuple, refresh rotates it, and protected calls—including nullable work-group switches—fall back only after a decoded JSON HTTP 401
    │   └── lihi-exceptions.php             Typed exception hierarchy (Auth / RegistrationCountryUnavailable / UserInvalid / Validation / SiteCreate with upstream message / NotFound / RateLimit / TokenInvalid / Server)
    ├── store/
    │   └── lihi-token-store.php        Lihi_Token_Store: request-memoized credentials plus fresh concurrency reads, renewable 20-second DB lease, guarded SQL upsert, CAST(... AS BINARY) raw CAS cleanup, scoped cache invalidation, and lifecycle fencing
    └── service/
        └── lihi-service.php            Server-side PKCE login, register/logout, credential validation/persistence, shared-lock Login/Refresh/Logout serialization, and protected profile/domain-option/work-group/short-URL/passthrough workflows
```

### Auth flow

1. A disconnected admin chooses one of two forms. **Register** sends email, password, and the WordPress hostname. lihi derives country, IP, and device from that registration request, rejects unavailable countries before creating a temporary record or sending mail, and otherwise snapshots those values with the password hash for account creation when the email link is opened. The verification click's IP and device do not replace registration metadata. Registration does not write the plugin credential bundle, issue credentials to WordPress, or log the account in. After verifying, the admin returns to **Login**.
2. **Login** first verifies that the activation generation captured by the request is still current, acquires the site-wide DB auth lock, and verifies that generation again before any remote call. It then sends email, password, the hostname parsed from `home_url()`, and a WordPress PHP-generated `base64url(sha256(verifier))` PKCE challenge to `/auth/login`. lihi binds that hostname to the eventual WordPress client and returns a five-minute, single-use authorization code. The service rechecks the activation generation when that response arrives, then the same PHP request exchanges the code plus the verifier retained in memory at `/auth/token`.
3. The exchange returns a server-generated Login-session identifier in `uuid`, `token` (the one-hour access JWT), and a rotating refresh token (absolute lifetime about 168 days). Every auth response is accepted only when its HTTP status is 2xx and its decoded JSON has `result: true`. The service accepts a 16–128 character opaque identifier using the upstream-safe alphanumeric / `_` / `-` character set instead of coupling storage to UUIDv4. It stores the normalized email plus identifier, access, and refresh values together in the non-autoloaded `lihi_auth_tokens` option. The 20-second lease is atomically renewed between Login's two remote calls and again before persistence, with an activation-generation recheck before each renewal, then released on every exit. There is no separate `lihi_email` option. Disconnected Login/Register inputs are intentionally blank because no last-known email is stored. Passwords and credentials are never returned to browser JavaScript.
4. Every protected client method receives the current access token and a fallback callback. On a valid JSON HTTP 401 response only, the fallback acquires that same site-wide auth lease, fresh-reads the tuple, and either reuses a concurrent request's newer access token or retains the lease while exchanging the stored identifier + refresh token, rechecking the activation generation and renewing after the remote response, validating it, and atomically replacing the bundle. Concurrent Refresh waiters and local Logout allow up to 18 seconds for that 15-second HTTP leg plus persistence. The original protected endpoint is retried exactly once. Malformed or non-JSON 401 responses fail closed without refresh.
5. Once refresh has been attempted, every failure path—invalid or expired token, validation / HTTP 429, network / 5xx, malformed success data, or guarded persistence failure—attempts a lease-owned conditional cleanup only if the credentials still carry the same server-issued Login-session identifier, then requires Login again. A cleanup/release database failure never replaces that re-login error, and a newer Login with a different identifier remains untouched. A direct protected-endpoint HTTP 429 or service failure does not itself start refresh. Expected non-5xx `User Invalid` or `user_not_found ,please login again` responses, plus a second HTTP 401, are terminal protected failures and instead conditionally clear only the tuple whose access token is still the rejected value, preserving any concurrent refresh. Every HTTP 5xx remains a server failure regardless of body message and therefore does not clear credentials as an identity failure.
6. Connected state always means one complete valid `{ email, uuid, access_token, refresh_token }` tuple under the activation generation captured by the current request. Normal helper reads memoize that tuple for the PHP request, while auth concurrency paths use explicit fresh reads. A marker prefix distinguishes an existing empty value from a missing row. Bootstrap-time `lihi_email()` / `lihi_is_authenticated()` probes catch store failures and degrade to disconnected so an options-table error cannot white-screen wp-admin; service operations still surface database failures.
7. Credential persistence requires current epoch plus current 20-second auth-lease ownership. One `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` statement compares both captured epoch and owner with `CAST(... AS BINARY)` byte-exact values before it can insert or overwrite the tuple. The raw serialized tuple is then direct-read and the guards are revalidated.
8. Access/session cleanup also requires lease ownership and uses the exact raw serialized tuple with a `CAST(option_value AS BINARY)` compare-and-swap delete. Direct writes and deletes invalidate the individual non-autoloaded option, `notoptions`, and the request memo; they do not evict the unrelated site-wide `alloptions` cache. Database API absence, query errors, and read-back errors consistently throw; normal contention and guard mismatch alone return `false`.
9. Lease acquisition and epoch enable use non-autoloaded `INSERT IGNORE` statements and accept only affected rows strictly equal to `1`. Only a byte-exact owner can renew or release the lease. If a successful renewal cannot be verified by direct read-back, the store immediately compare-deletes that exact renewed value before forgetting ownership, avoiding a live orphan lease. Malformed or empty epoch rows can be removed and re-enabled; malformed, empty, implausibly future, or more than 20 seconds old lease rows can be exact-deleted and replaced. Refresh waiters stop polling as soon as the lease disappears or expires; exhausted contention is reported separately from persistence failure.
10. Activation, deactivation, and uninstall use a complete lifecycle transition. It removes the epoch before waiting, acquires the shared lock within 22 seconds, repeats disable and credential purge under the lock, then either installs a fresh epoch or confirms authentication remains disabled. Login and Refresh recheck that fence after a remote response and therefore cannot renew or begin another HTTP leg after lifecycle cleanup starts. The 22-second drain exceeds the 20-second lease while remaining below the common 30-second PHP execution limit. Self-loading callbacks do not depend on admin bootstrap. If the transition fails, best-effort disable/delete attempts are isolated so hook execution continues; an absent epoch keeps any undeleted secret unusable, and a foreign lock remains untouched. This development branch reads only the current bundle shape.

### Short URL flow

1. PHP outputs an empty state container for each supported item; `lihi-button.js` appends a **Create** or **Copy** button into that container.
2. Editor clicks a **Create** button in the post list or media attachment panel, then `lihi-button.js` opens a modal and loads domain choices plus UTM source / medium choices from `wp_ajax_lihi_url_options`. The frontend caches that combined options response for 60 seconds; Domain, UTM source, and UTM medium use the same select loading UI. There is no account-default fallback option; the modal submits the selected non-empty domain value and lets the lihi service decide whether it is valid. The Domain label row includes **Custom domain?**; administrators use the localized `/myDomain` passthrough target, while non-managers open `https://lihidomain.com`. Administrators also see **Manage options?** below UTM source / medium using `/profile#utm-setting`; each passthrough action first asks whether to open lihi, then calls `wp_ajax_lihi_passthrough_nonce` and opens lihi-admin in a new tab without closing the create modal. The Tags field has no default selected tags; it shows recommended tags (`wordpress`, site host, and item type) as click-to-add buttons, and only chips selected inside the tag input are submitted. UTM fields are optional for non-media items; media / attachment items hide the UTM controls and submit blank UTM values.
3. On submit, `wp_ajax_lihi_create_url` validates nonce, item type, `read_post`, the complete connected credential bundle, and that a domain value is present, then passes domain, user tags, and UTM to `Lihi_Service::get_or_create_short_url()`.
4. The service checks for an existing short link via `get_short_link()` (`GET /site/find`, reading `data.site`); creates one with `create_site()` (`POST /site/store`) if none is found. The create payload sends only selected tags to lihi as a comma-separated string and appends UTM parameters directly to the destination URL. A `need_upgrade` rejection receives a dedicated exception and returns `code: need_upgrade` with plugin-owned gettext copy; `site_create_fail` remains a normal validation error, and upstream 5xx responses remain server errors.
5. On success, the AJAX handler writes `lihi_already = 1` to the item's post meta and returns the short URL plus that state. The frontend renders the clicked button as **Copy**, then copies the URL; if clipboard access is blocked, it shows the short URL in a prompt for manual copy.
6. When a **Copy** button is clicked later, `wp_ajax_lihi_copy_url` calls `get_existing_short_url()` only. If the upstream short URL is missing, it writes `lihi_already = 0`, returns a 410 `lihi_missing` error, and the frontend changes the button back to **Create**, shows "Short URL has been removed. Please create it again." in a confirm modal, then opens the create modal after OK. Other Copy API errors only show the error and keep the button state unchanged.
7. Administrators with `manage_options` see the adjacent **Edit** button. When it is clicked, the frontend asks the admin to confirm opening lihi, generates a browser verifier and `base64url(sha256(verifier))` challenge, then calls `wp_ajax_lihi_copy_url` to verify the upstream short URL still exists. The frontend sends that short URL as the absolute-URL `target` to `wp_ajax_lihi_passthrough_nonce`, then opens `/api/wordpress/v1/passthrough/redirect` in a new tab with `nonce` plus `verifier` as GET query params; lihi-admin redirects absolute URL targets to its site search with `tag`. If the upstream short URL is missing, the same 410 `lihi_missing` response resets the UI back to **Create** and shows the removed-short-url message.

## API Reference

See [`docs/lihi-api-endpoints.md`](docs/lihi-api-endpoints.md) for the lihi API contract, request/response shapes, and error mapping. The document intentionally describes API behavior without referencing internal source or documentation locations.
