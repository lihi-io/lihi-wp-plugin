# Test TODO

測試框架：PHP 7.4 / PHP 8.2 PHPUnit containers + PHPUnit 9 + Brain\Monkey（mock WordPress 函式）+ WP_UnitTestCase（整合測試，需要 DB）。

Composer 僅在官方 `php:*-cli` 測試 container 內執行；PHP 7.4 與 PHP 8.2 分別使用獨立 Composer file / lock file。只有 `lihi-short-url/`、`tests/`、`patchwork.json`、`phpunit.xml` 以唯讀方式掛到 `/app/code`；vendor directory 與 WordPress core install 存在 Docker named volumes，不寫入 repo 工作樹。

`make test` 會先停掉開發用 `wordpress` / `db` services，再用 `db_test` 依序跑 `phpunit74`、`phpunit82`，每個 PHPUnit container 跑完即停止。單版使用 `make test74` / `make test82`；coverage targets 採相同逐版模式。

目前 test suite 為 **240 tests / 635 assertions**，涵蓋 PHP 7.4 與 PHP 8.2。

CI / packaging：目前 release metadata 為 `1.0.6`，plugin header、WordPress.org Stable tag / changelog / upgrade notice、兩個 test container 的 `COMPOSER_ROOT_VERSION`、asset cache prefix 與翻譯 catalog version 必須一致。`.github/workflows/package-plugin.yml` 只在 tag push 時執行；Package job 打包 `lihi-short-url/` 成 `build/lihi-short-url.zip`，驗證主檔與 `readme.txt`，上傳 artifact `lihi-short-url-plugin`；release job 下載同一 artifact 建立或更新 GitHub Release。

| Test class | 基底 | 主要範圍 |
|---|---|---|
| `AuthClientTest` | `TestCase` + Brain\Monkey | Register / Login / authorization-code / refresh / one-shot Logout payload 與 auth error mapping |
| `ClientTest` | `TestCase` + Brain\Monkey | 所有 protected endpoints（含 domain/work-group endpoints）的 access fallback、單次 retry、method/path/body 與 fail-closed HTTP parsing |
| `TokenStoreTest` | `TestCase` + Brain\Monkey | direct-DB credential/epoch/lock reads、INSERT IGNORE mutex、wait/TTL lifecycle budgets、scoped option-cache invalidation、exact-value conditional deletes |
| `TokenStoreDatabaseTest` | `WP_UnitTestCase` + real MySQL options table | raw serialized tuple CAS、binary exactness、malformed row repair、guarded upsert race |
| `ServiceTest` | `TestCase` + Brain\Monkey | server-side PKCE、activation rechecks、credential persistence、profile shape normalization、single-flight refresh、protected workflows |
| `AjaxAuthenticationTest` | `TestCase` + Brain\Monkey | Login / Register / Logout / work-group options/switch / dashboard AJAX |
| `AjaxCopyUrlTest` | `TestCase` + Brain\Monkey | options / create / copy / passthrough AJAX |
| `SettingsPageTest` | `WP_UnitTestCase` | Login-default auth tabs、無 JS query tab fallback（含 non-scalar input）、connected profile / work-group modal UI |
| `HelperTest` | `WP_UnitTestCase` | bundle-backed email / connected guard / singleton composition |
| `PluginHooksTest` | `WP_UnitTestCase` | AJAX actions、admin assets、columns、settings registration |
| `PluginLifecycleTest` | `WP_UnitTestCase` | activation generation、deactivation / activation cleanup、uninstall remote Logout成功與失敗路徑 |
| `AdminNoticeTest` | `WP_UnitTestCase` | connected / disconnected 都不註冊 dashboard-wide setup notice |
| `PluginLoadedTest` | `WP_UnitTestCase` | plugin bootstrap |

---

## Credential helpers and composition

- [x] `lihi_email()` 從完整 `lihi_auth_tokens` bundle 回傳 email
- [x] bundle 缺欄、email 空白、server session identifier 無效、access / refresh 空白或 option 不存在時，`lihi_email()` 回空字串
- [x] `lihi_is_authenticated()` 只接受一個完整 `{ email, uuid, access_token, refresh_token }` bundle
- [x] `lihi_email()` / `lihi_is_authenticated()` 遇到 store read exception 時 fail closed，bootstrap include 不會讓整個 wp-admin fatal
- [x] `lihi_api_host()` 由 `Lihi_Singletons::lihi_client()` 傳給 client；client constructor 不再接收本機 UUID
- [x] production app/API host固定為 `https://app.lihi.com`；client、passthrough與password-reset URL測試皆禁止回退到 `app.lihidev.com`
- [x] client / service / token store 僅透過 `Lihi_Singletons` 組裝；沒有 global wrappers
- [x] `lihi_site_host()` 取 `home_url()` host，供 Register payload 與 short-link type namespace 使用
- [x] `lihi_resolve_url()`：post / page 使用 permalink，attachment 使用 attachment URL，無法解析時拋例外
- [n/a] 開發版 credential store 只接受 current bundle shape（程式碼審查保證）

---

## Lihi_Token_Store

- [x] `get()` direct-read 後 memoize normalized tuple；`get_fresh()` 強制重新讀取並更新 memo，concurrency-sensitive service paths一律使用 fresh read
- [x] 任一欄位缺失、型別錯誤、email 空白、server identifier非 16–128 字元 `[A-Za-z0-9_-]` 或 token 空白 → `false`
- [x] server identifier保留原始大小寫，接受 UUIDv7 / ULID 等 opaque格式，不綁 UUIDv4
- [x] `set()` 要求 current epoch 與 current lock ownership，使用單一 `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` guarded upsert；SQL 以 `CAST(... AS BINARY)` 同時精確比對 captured epoch + lock owner
- [x] Guarded upsert 在 epoch 或 owner 被替換後無法 overwrite；query failure、raw DB read-back mismatch、epoch/owner revalidation failure → `Lihi_Server_Exception`
- [x] `delete()` direct query 必須不是 `false`，並以 direct read-back確認 credential row 不存在；query/read-back error fail closed
- [x] `delete_if_access_token()` / `delete_if_uuid()` 都要求 current lock owner，保留 direct-read raw serialized tuple，再以 `CAST(option_value AS BINARY)` CAS delete；不將 normalized tuple重新 serialize
- [x] Raw CAS 可處理不同 key order、額外 key與 case 差異，並保留任何 intervening replacement
- [x] epoch / lock及 concurrency-sensitive credential reads使用 direct query；一般 helper credential reads共用 request memo
- [x] Direct read 使用 marker 區分 missing row 與 present empty row；`$wpdb->last_error` / DB API缺失都拋 `Lihi_Server_Exception`
- [x] insert / guarded persist / delete / release / renew的 DB API缺失或 query failure一致拋 `Lihi_Server_Exception`；`false`只代表正常 contention / guard mismatch
- [x] constructor 捕捉一次 direct-read `lihi_auth_epoch`；`get()` / `ensure_enabled()` / `set()` 比對 captured generation，不受 option/object cache 舊值影響
- [x] missing、malformed、empty 或不同 epoch 時 credential read fail closed；`disable()` 可 exact-delete malformed/empty row，之後重新 enable
- [x] `disable()` direct-read epoch、exact-value compare-delete，並 direct-read 驗證不存在
- [x] `enable()` 以 direct `INSERT IGNORE` 建立 random 32-byte / 64-hex、non-autoload generation；只接受 affected rows integer `1`，再 direct-read驗證，mismatch 時 exact-value cleanup
- [x] lock 使用 non-autoload `lihi_auth_tokens_lock` DB option；acquire 以 direct `INSERT IGNORE` 且只接受 affected rows integer `1`
- [x] lock owner 才能 release；foreign replacement 不會被誤刪
- [x] lock 是 20-second renewable lease；只有 byte-exact owner能以 CAS更新 timestamp / owner value
- [x] renew UPDATE成功但 direct read-back mismatch時，立即 exact-delete renewed value後才清除 local ownership，不留下20秒 orphan lease
- [x] malformed、empty、超過20秒未續租或 timestamp超前超過20秒的 lock row可 exact-delete後替換
- [x] `wait_for_access_token_change()` 的 credential / lock polling全用 fresh direct DB，預設18秒涵蓋15秒 HTTP timeout；lock已釋放或已 stale但沒有rotation時立即停止等待
- [x] 同一把 auth lock 供完整 Login、完整 Refresh 與 Logout 共用，三種 critical sections 不會重疊
- [x] 每個成功 direct write/delete invalidates individual option key與`notoptions`；三個 non-autoload auth options不清除site-wide `alloptions`
- [x] 每個成功 direct mutation同步 invalidates request credential memo
- [x] `transition_activation()` 在等待前 disable，22秒內取得20秒 TTL lock後再次 disable + purge，activation才 enable fresh epoch；任何 exit release owned lock，wait budget低於30秒

---

## Lihi_Client auth contract

### Register

- [x] `register()` → POST `/api/wordpress/v1/auth/register`
- [x] body 精確為 `{ email, hostname, password }`；hostname 來自 `home_url()`，不傳 UUID / `is_mobile`
- [x] HTTP 403 `registration country unavailable` → `Lihi_Registration_Country_Unavailable_Exception`
- [x] HTTP 409 `account already exists` → `Lihi_Account_Already_Exists_Exception`
- [x] HTTP 429 → `Lihi_Rate_Limit_Exception`
- [x] Auth response 只有 HTTP 2xx 且 `result: true` 才成功；非 2xx 即使 body 宣稱 `result: true` 仍 fail closed

### Login and authorization-code exchange

- [x] `login()` → POST `/api/wordpress/v1/auth/login`
- [x] body 精確為 `{ email, hostname, password, code_challenge }`；hostname 來自 `home_url()`，不傳 UUID / `is_mobile`
- [x] `account does not exist`、`password invalid`、`User Invalid` 分別映射到專用 exception
- [x] `exchange_authorization_code()` → POST `/api/wordpress/v1/auth/token`
- [x] exchange body 精確為 `{ grant_type: "authorization_code", code, code_verifier }`
- [x] HTTP 403 `code invalid` → `Lihi_Authorization_Code_Invalid_Exception`
- [x] Login / authorization-code exchange 只有 HTTP 2xx 且 `result: true` 才能回傳 data

### Refresh

- [x] `refresh_access_token()` → POST `/api/wordpress/v1/auth/token`
- [x] refresh body 精確為 `{ grant_type: "refresh_token", uuid, refresh_token }`
- [x] success response 保留 server response fields `uuid` / `token` / `refresh_token`
- [x] HTTP 403 `refresh token invalid` → `Lihi_Refresh_Token_Invalid_Exception`
- [x] auth endpoint rate limit 使用 typed exception；network / 5xx / unexpected failure envelope fail closed
- [x] Refresh 只有 HTTP 2xx 且 `result: true` 才能回傳 rotated credentials

### Logout

- [x] `logout()` → POST `/api/wordpress/v1/auth/logout`
- [x] 使用目前 access token的 Bearer header，不送 request body
- [x] 使用5秒 timeout；HTTP 401直接拋 token-invalid，不進 access fallback、不 refresh、不 retry

---

## Protected client fallback

`get_profile()`、`get_options()`、`get_group_options()`、`switch_group()`、`create_passthrough_nonce()`、`get_short_link()`、`create_site()` 都接收 access token 與 fallback callback。

- [x] 每個 protected method 第一次 HTTP 401 時呼叫 fallback，使用 replacement access token 重送相同 method / URL / payload 一次
- [x] fallback callback 收到實際被拒絕的 access token
- [x] retry 再次 HTTP 401 時向上拋出，不會第三次 request 或第二次 fallback
- [x] fallback 回空 token時不發第二個 request
- [x] HTTP 400 / 403 / 404 / 429 / 5xx 都不呼叫 access fallback
- [x] malformed / non-JSON HTTP 401 fail closed 為 `Lihi_Server_Exception`，不呼叫 fallback；只有可解析的 API 401 envelope 可 refresh
- [x] empty HTTP 500 response fail closed
- [x] HTTP 500 即使 body 使用 `User Invalid` 或 `user_not_found` 仍為 `Lihi_Server_Exception`，不當成 terminal identity failure
- [x] GET query、POST JSON body、Authorization header 與 passthrough target / challenge 保持原契約
- [x] Domain options 使用 `GET /user/domain-options`；work-group options 使用 `GET /user/group-options`
- [x] Work-group switch 使用 `POST /user/switch-group` 並保留 `{ group_id: int|null }` JSON body

---

## Lihi_Service authentication and rotation

### Login / Register / Logout

- [x] Login 在 PHP server 產生 43-character PKCE verifier 與 S256 challenge
- [x] Login 先取得 authorization code，再以同一 verifier exchange
- [x] exchange response 必須包含 valid opaque server session identifier、access token、refresh token
- [x] Login 在任何遠端呼叫前取得 auth lease，並跨 `/auth/login`、authorization-code exchange、response validation 與 atomic bundle persistence 全程持有
- [x] Login 在兩段 remote calls之間及 persistence前原子續租；任一續租失去 ownership時停止流程且不寫 credentials
- [x] Login 在取得 auth lock 前、取得後及每段 remote response回來後都 `ensure_enabled()`；deactivation / epoch rotation 發生時不開始下一段 HTTP、不再續租或持久化 credentials
- [x] service 將 normalized `{ email, uuid, access_token, refresh_token }` 在同一 DB auth lock 內一次保存
- [x] authorization code 缺失、不完整 exchange 或 persistence failure 不留下 partial credentials；lock 必定 release
- [x] Register 只呼叫 client，不寫 credential bundle
- [x] Register 在遠端呼叫前 `ensure_enabled()`，disabled / stale epoch request fail closed
- [x] Logout 使用 Login / Refresh 的同一 auth lock，fresh-read目前 access token、one-shot呼叫 remote Logout，再刪除完整本機 bundle並 release
- [x] Remote Logout 的 network / HTTP / response failure會被吞掉，本機 bundle仍必定進入 delete；remote錯誤不會把 UI卡在 connected

### Single-flight access fallback

- [x] protected call 在本機 bundle 不完整時直接要求 Login
- [x] protected workflow 在讀取 bundle 前 `ensure_enabled()`；refresh 在進入、取得 lock 後及 remote response回來後再次檢查 epoch
- [x] Profile / options / find / store / passthrough 都從 persisted bundle 使用 access token
- [x] HTTP 401 fallback 取得同一 auth lease，並從 final tuple re-read跨遠端 refresh、相同 session identifier validation到 atomic bundle replacement全程持有
- [x] Refresh remote response回來後先重驗 epoch，再於 validation / persistence前原子續租；續租失敗清除matching old session並要求重新 Login
- [x] refresh 以 persisted UUID + refresh token rotation，保留 email，並 atomic replace 全 bundle
- [x] 同一 workflow 的前一 endpoint refresh 後，後續 endpoint 使用新 access token
- [x] fallback 先 re-read：若其他 request 已輪替，直接重用新 access token
- [x] lock 被占用時最多等待18秒讓 winner完成15秒 HTTP並寫入新 tuple；missing / stale lease提早停止等待，timeout後不自行並行 refresh
- [x] refresh 一旦嘗試，任何 `Throwable`（invalid token、429、validation、network / 5xx、malformed success 或 persistence failure）都呼叫 `delete_if_uuid()` 清除相同 server-issued Login-session UUID 的 tuple，並統一要求重新 Login
- [x] refresh guarded persistence failure 以 UUID 清除該失敗 session
- [x] refresh cleanup 本身失去 lock或發生 DB error時仍保留原 refresh failure，對使用者回要求重新 Login訊息
- [x] refresh failure 的 UUID conditional delete 不會刪除具有不同 UUID 的較新 Login
- [x] 所有已取得 auth lock 的 Refresh success、concurrent-token reuse、terminal error 與 attempted-refresh failure paths 都會 release
- [x] upstream 已 rotation 但 response malformed 時清除無法再使用的舊 tuple
- [x] protected `User Invalid` / `user_not_found` / 第二次 401 是 terminal failure，不 refresh，改以 `delete_if_access_token()` conditional-delete matching tuple
- [x] Register PHP 與 JavaScript 都直接計算 raw password 的 Unicode code points；剛好 6 個可通過，前端不額外 trim 拒絕
- [x] terminal protected failure 不刪除另一 request 已更新 access token 的 tuple

### Short URL workflows

- [x] Find 命中 `data.site` 時直接回既有短網址
- [x] Find 空值時才呼叫 Create
- [x] Copy-only flow 永不 Create；短網址不存在時拋 `Lihi_Not_Found_Exception`
- [x] Create payload 保留 domain、host-namespaced type、單一 type ID、selected comma-separated tags
- [x] UTM 直接附加到 destination URL，不傳獨立 `utm` object
- [x] attachment 使用 attachment URL
- [x] passthrough 回傳 protected client 的 `data.nonce`

---

## Settings authentication AJAX

- [x] `lihi_login` / `lihi_register` / `lihi_logout` 都要求 action-specific nonce 與 `manage_options`
- [x] Login 在呼叫 service 前驗證 email / password
- [x] Login password 只 `wp_unslash()`、不 sanitize、不保存
- [x] Login success response 只回 authenticated / message，不回 UUID、access、refresh、authorization code 或 verifier
- [x] Login 的 account missing、password invalid、invalid code、validation、rate limit、account unavailable、service unavailable 使用明確 HTTP status 與友善訊息
- [x] Login auth lease contention使用獨立 HTTP 409訊息，不誤報 token persistence failure
- [x] Login / Register 捕捉 unexpected `Throwable` 並回 generic 503；`WP_DEBUG` diagnostics 只記 exception class、不記 message，避免 argument / password 洩漏
- [x] Register 需要 valid email、至少 6 個 Unicode code points 的 password、明確 account-creation consent；PHP 與 JS 計數規則一致
- [x] Register 傳 raw password 給 service，但 success 只回 verification sent；不建立本機 session
- [x] Register country unavailable 使用 plugin-owned gettext 文案，不向瀏覽器回傳 upstream `msg`
- [x] Existing account Register → HTTP 409，引導使用 Login
- [x] Logout 清除 atomic local bundle
- [x] Dashboard disconnected 時開 public lihi home；connected 時使用 protected passthrough fallback

---

## Settings page rendering

- [x] Disconnected page 同時保留獨立 `lihi-login-form` 與 `lihi-register-form`，以互斥 tabs 顯示；預設只顯示 Login
- [x] `?lihi_auth_tab=register` 在無 JavaScript 時只顯示 Register panel；query value 直接 unslash + sanitize，無效或 non-scalar 值回到 Login；此 read-only UI preference 不要求 nonce
- [x] Login / Register 各自有 email、password、status；Register 另有唯一 consent checkbox
- [x] Login / Register 都是 `method="post"`、action 指向 `admin-ajax.php` 的 native fallback forms，並各自包含 hidden `action` 與 `nonce`
- [x] Disconnected page不預填 email、password、session identifier、access token或refresh token；沒有不可達的 previous-session提示
- [x] Connected page隱藏 auth forms，顯示 bundle email、profile role / work group 與 Logout；`group_name: null` 顯示 `My Work Group`
- [x] Profile response缺欄 normalize為`null`；`data`或`user_role` / `group_name`型別錯誤時拋service error，不把array傳入`esc_html()`
- [x] Connected page輸出 Switch button 與具 label/description、初始 hidden、focusable dialog 的 work-group modal；intro 不建立 stacking context，dialog/backdrop 保持在後續 dashboard workspace 上方
- [n/a] Service heading 以兩個 sentence-level block spans 在句點處換行，各句在窄螢幕仍可自然折行（markup / CSS review）
- [n/a] Local validation error 設定 `aria-invalid` / `aria-describedby`、連結 live status 並 focus 欄位（JS review）
- [n/a] Register 成功把 email 帶到 Login、切回 Login tab但不切換 connected UI；Login / Logout / work-group switch 成功顯示訊息 2 秒後才 reload（JS review）
- [n/a] Inline notice 使用 `textContent`；只有 structured password-invalid response 可加入安全 reset link（JS review）
- [n/a] Auth tabs支援 Arrow/Home/End；work-group modal支援 Escape、focus trap、focus restore，選項與訊息只以 `textContent` 呈現（JS review）

---

## Settings work-group AJAX

- [x] `lihi_group_options` / `lihi_switch_group` 都驗證 nonce、`manage_options` 與 complete credential bundle
- [x] Options response要求 non-empty groups、每項 nullable-or-positive ID / scalar-or-null name，且目前 `group_id` 必須存在於選項
- [x] Switch request只接受 present 的空字串（對應 API `null`）或正整數 ID；0、負數、非數字在呼叫 service 前回 HTTP 400
- [x] Service switch response必須回傳與 requested nullable ID 相同的 `group_id`
- [x] Work-group handlers沿用 401 expired、403 auth/account、409 disconnected/busy、429 rate limit、503 service、500 unexpected error mapping

---

## Short URL / passthrough AJAX

現行四個 actions：`lihi_url_options`、`lihi_create_url`、`lihi_copy_url`、`lihi_passthrough_nonce`。Edit UI 以 Copy 查詢後再呼叫通用 passthrough action。

- [x] 所有 handlers 驗證 nonce、server-side item type 與 `read_post`
- [x] incomplete credential bundle → HTTP 409，不呼叫 protected service
- [x] Passthrough 另要求 `manage_options`、valid 43-character browser challenge 與 allowlisted target shape
- [x] Options 回傳 normalized domain `{ value, label }` 與 UTM source / medium
- [x] Create 要求 non-empty domain，sanitize domain / tags / UTM，成功寫 `lihi_already = 1`
- [x] Copy 只查既有 URL；missing 時寫 `lihi_already = 0` 並回 HTTP 410 / `lihi_missing`
- [x] Passthrough 接受 absolute short URL、`/myDomain`、`/utm`，只回 nonce / redirect URL
- [x] Exception mapping：400 validation、400 `need_upgrade` dedicated error、401 expired session、403 permission / account unavailable、409 disconnected / auth busy、410 missing URL、429 rate limit、503 service unavailable、500 unexpected
- [x] `POST /site/store` 的 `need_upgrade` 對應獨立 exception、`code: need_upgrade` 與 plugin-owned gettext message；`site_create_fail` 維持一般 validation error
- [n/a] Copy 只有 `lihi_missing` 才切回 Create 並開 modal；其他錯誤保留狀態（JS review）
- [n/a] Edit 使用 `lihi_copy_url` + `lihi_passthrough_nonce`，不需要專用 AJAX action（JS review）
- [n/a] Media modal 隱藏 UTM；recommended tags 需點擊才送；clipboard failure 提供 manual prompt（JS review）

---

## Plugin hooks and lifecycle

- [x] Settings actions：`wp_ajax_lihi_login`、`wp_ajax_lihi_register`、`wp_ajax_lihi_logout`、`wp_ajax_lihi_group_options`、`wp_ajax_lihi_switch_group`、`wp_ajax_lihi_dashboard_passthrough`
- [x] Short URL actions：`wp_ajax_lihi_url_options`、`wp_ajax_lihi_create_url`、`wp_ajax_lihi_copy_url`、`wp_ajax_lihi_passthrough_nonce`
- [x] Settings page不註冊 split email setting；identity 與 tokens 共用 atomic option
- [x] Settings → lihi Short URL menu 已註冊
- [x] Connected guard 控制 list columns、attachment field與 assets；disconnected 時不註冊 Short URL UI
- [x] Post / media columns 與 attachment panel輸出 frontend mount container，反映 `lihi_already`
- [x] Assets 僅在 edit / upload / post / post-new screens enqueue，split JS dependency order 正確
- [x] Activation 完整 transition：先 disable fence、22-second wait取20-second TTL lock、lock內再次 disable/purge、enable fresh non-autoload epoch、release；啟用後保持 disconnected
- [x] Deactivation / uninstall 完整 transition：先 disable fence、22-second wait取20-second TTL lock、lock內再次 disable/purge/驗證仍 disabled、release
- [x] `transition_uninstall()` 先 disable fence並取得 lifecycle lock，再以鎖內最終 access token觸發最多5秒的 remote Logout；測試確認 request發生時 epoch已移除、tuple仍存在且lock已持有，remote `WP_Error` 也不阻止 token/lock/epoch cleanup
- [x] Activation / deactivation callbacks 自行 `require_once` exceptions 與 TokenStore，不依賴 admin-only bootstrap 或 singleton registry
- [x] Lifecycle transition failure 時 best-effort fallback 分別嘗試 `disable()` / `delete()` 並吞掉 cleanup errors，不讓 hook fatal
- [x] Lifecycle fallback 不直接刪除 foreign auth lock；epoch 缺失時 authentication 維持 disabled，unguarded late write 無法通過 guarded upsert
