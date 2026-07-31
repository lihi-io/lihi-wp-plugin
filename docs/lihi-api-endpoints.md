# lihi WordPress API Endpoints

本外掛對接單一 lihi WordPress API。帳號建立與登入是兩條獨立流程：Register 只寄驗證信；已驗證帳號必須再用 Login 完成 server-side PKCE，才能取得 server-issued UUID、access token 與 rotating refresh token。外掛將登入 email 與這三項 credentials 保存在同一個非 autoload option，所有受保護 API 都能在 access token 被 HTTP 401 拒絕時 refresh 並重試一次。

目前開發版外掛的 base URL：

- `https://app.lihi.com/api/wordpress/v1`

Endpoint contract：

| Endpoint | Auth | 契約 |
|---|---|---|
| `POST /auth/register` | none | 檢查註冊 request 國家並快照 IP/device；允許時才建立驗證資料與寄信 |
| `POST /auth/login` | none | 驗證既有主帳號密碼與 PKCE challenge，回單次 authorization code |
| `POST /auth/token` | none | authorization-code exchange 或 refresh-token rotation |
| `GET /auth/verify-email` | verification token query | 瀏覽器 HTML flow；外掛不直接呼叫 |
| `GET /user/profile` | access token | 讀取方案角色與目前工作群組名稱 |
| `GET /user/domain-options` | access token | 建立 modal 的 domains / UTM options |
| `GET /user/group-options` | access token | 讀取此 WordPress client 可用的工作群組與目前 ID |
| `POST /user/switch-group` | access token | 切換此 WordPress client 使用的工作群組 |
| `POST /passthrough/nonce` | access token | 產生短效 nonce，供瀏覽器 GET 到 passthrough redirect |
| `GET /passthrough/redirect` | nonce query | 瀏覽器 HTML/session flow；不是 `Lihi_Client` method |
| `GET /site/find` / `POST /site/store` | access token | 查詢 / 建立短網址 |

受保護 endpoints 需要：

```http
Authorization: Bearer <data.token>
Content-Type: application/json
```

Auth endpoints 不使用 bearer token。Login 與 Register 都傳由 `home_url()` 解析出的 WordPress hostname；兩者都不傳 UUID 或 `is_mobile`。UUID 由第一次 authorization-code exchange 的 server response 產生。對 Register、Login、authorization-code exchange 與 refresh 而言，只有 HTTP 2xx 且 decoded JSON `result === true` 才算成功；非 2xx 即使 body 宣稱 `result: true` 也會 fail closed。Credential store 只接受本文件定義的 current bundle shape，不包含舊 auth shape 的相容或 migration path。

> 本 contract 不包含 `POST /mail`、site update/delete、`/posts` 或 `/site-urls` 系列 endpoints。

---

## POST `/auth/register`

建立新 lihi 主帳號的短效驗證資料並寄驗證信。設定頁 Register form 與 Login form 完全分開；外掛先驗證 `manage_options`、AJAX nonce、email、至少六個字元的 password，以及明確的 account-creation consent，再呼叫此 API。Consent 只在 WordPress 端檢查，不是 API body。

Body：

```json
{
  "email": "alice@example.com",
  "hostname": "example.com",
  "password": "account-password"
}
```

- `email`：required email，最大 254 字元；server 會 trim 並轉小寫。
- `hostname`：required string；外掛從 `home_url()` 取 host，server 再正規化為小寫 host。
- `password`：required string，至少 6 字元。外掛只做 WordPress unslash，不 sanitize 或保存密碼。

Server 以註冊 request 的實際來源 IP 執行 GeoIP，並從同一 request 的 User-Agent 判斷 device。Client 傳入的 `country`、`registered_ip` 或 `registered_device` 不屬於 body contract，也不會覆蓋 server-derived 值。國家無法判定或不允許註冊時，server 會在寫入 temporary registration record 與寄信之前拒絕 request。

Response 200：

```json
{ "result": true, "msg": "" }
```

成功只代表驗證信已寄出。Server 將 hostname、email、password hash、country、registration IP 與 device 放在 600-second registration record；使用者必須在信中完成驗證，再回 WordPress 使用 Login。Register response 沒有 `data`、UUID 或 tokens，外掛也不寫入 credential option，因此註冊成功不會讓站台進入 connected state。

錯誤：

- HTTP 400 validation：`{ "result": false, "msg": { ...field errors... } }` → `Lihi_Validation_Exception`
- HTTP 400 hostname 無法正規化：`{ "result": false, "msg": "bad request" }` → `Lihi_Validation_Exception`
- HTTP 403 國家無法判定或不可註冊：`{ "result": false, "msg": "registration country unavailable" }` → `Lihi_Registration_Country_Unavailable_Exception`；AJAX 改回 plugin-owned gettext 文案，不直接顯示 upstream `msg`
- HTTP 403 已刪除、停用或不可使用：`{ "result": false, "msg": "User Invalid" }` → `Lihi_User_Invalid_Exception`
- HTTP 409 已有可用主帳號：`{ "result": false, "msg": "account already exists" }` → `Lihi_Account_Already_Exists_Exception`
- HTTP 429：`{ "result": false, "msg": "Too Many Attempts." }` → `Lihi_Rate_Limit_Exception`
- Mail / Redis / server failure（HTTP 5xx 或 failure envelope）→ `Lihi_Server_Exception`

---

## POST `/auth/login`

驗證既有、可用的 lihi 主帳號與密碼，並將 PKCE S256 challenge 綁定到 authorization code。子帳號不符合主帳號查詢，回 `account does not exist`。

Body：

```json
{
  "email": "alice@example.com",
  "hostname": "example.com",
  "password": "account-password",
  "code_challenge": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
}
```

- `email`：required email，最大 254 字元。
- `hostname`：required string；外掛從 `home_url()` 取 host，server 正規化後綁到 authorization code 與後續建立的 WordPress client。
- `password`：required string。
- `code_challenge`：required 43-character base64url value，即 PHP server 產生的 `base64url(sha256(code_verifier))`。PKCE 固定 S256，不送 `code_challenge_method`。

Response 200：

```json
{
  "result": true,
  "msg": "",
  "data": {
    "code": "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB"
  }
}
```

Authorization code 是 43-character base64url 字串，在 Redis 保存 300 秒，且只能成功交換一次。它不是 access token。

錯誤：

- HTTP 400 validation envelope → `Lihi_Validation_Exception`
- HTTP 403 `{ "result": false, "msg": "account does not exist" }` → `Lihi_Account_Not_Found_Exception`
- HTTP 403 `{ "result": false, "msg": "password invalid" }` → `Lihi_Email_Or_Password_Invalid_Exception`
- HTTP 403 `{ "result": false, "msg": "User Invalid" }` → `Lihi_User_Invalid_Exception`
- HTTP 429 `{ "result": false, "msg": "Too Many Attempts." }` → `Lihi_Rate_Limit_Exception`
- HTTP 5xx 或其他 `result !== true` → `Lihi_Server_Exception`

---

## POST `/auth/token` — authorization code

同一個 PHP Login request 將 authorization code 與原始 verifier 送回 server；瀏覽器不會接觸 code、verifier 或 token response。

Body：

```json
{
  "grant_type": "authorization_code",
  "code": "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB",
  "code_verifier": "CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC"
}
```

- `grant_type` 必須是 `authorization_code`。
- `code` 必須是 43-character base64url 字串。
- `code_verifier` 必須是 43–128 字元，字元集為 `[A-Za-z0-9-._~]`。

Response 200：

```json
{
  "result": true,
  "msg": "",
  "data": {
    "uuid": "2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e",
    "token": "access.jwt.value",
    "refresh_token": "refresh.jwt.value"
  }
}
```

`data.token` 是 access token；API 不使用 `access_token` 作為 response field。`uuid` 是 server-generated Login-session identifier，每次完整 authorization-code exchange 都會建立新值。外掛將它視為 16–128 字元、字元集 `[A-Za-z0-9_-]` 的 opaque identifier，因此不綁定 UUIDv4，能接受 UUIDv7、ULID 或後續同字元集格式。Authorization code 成功交換後立即失效。

錯誤：

- HTTP 400 validation envelope → `Lihi_Validation_Exception`
- HTTP 403 過期、已消耗、未知 code 或 PKCE verifier 不符：`{ "result": false, "msg": "code invalid" }` → `Lihi_Authorization_Code_Invalid_Exception`
- HTTP 403 code 所屬 user / group 已失效：`{ "result": false, "msg": "User Invalid" }` → `Lihi_User_Invalid_Exception`
- HTTP 429 → `Lihi_Rate_Limit_Exception`
- HTTP 5xx 或不完整 credential response → `Lihi_Server_Exception`

---

## POST `/auth/token` — refresh token

以目前保存的 UUID 與 raw refresh token 輪替完整 token pair。

Body：

```json
{
  "grant_type": "refresh_token",
  "uuid": "2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e",
  "refresh_token": "current.refresh.jwt"
}
```

- `grant_type` 必須是 `refresh_token`。
- `uuid` 必須 byte-for-byte 使用 server 先前回傳的 Login-session identifier。
- `refresh_token` 必須是三段 base64url JWT，最大 2048 字元。

Response 200 與 authorization-code exchange 完全相同：

```json
{
  "result": true,
  "msg": "",
  "data": {
    "uuid": "2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e",
    "token": "new.access.jwt",
    "refresh_token": "new.refresh.jwt"
  }
}
```

每次成功 refresh 都立即使舊 refresh token 失效。Server 只保存 refresh token 的 SHA-256 hash；WordPress 必須保存 raw value。新 refresh token 沿用最初 token 的 absolute expiration，不延長 session；access token TTL 為 3600 秒，refresh token 初始 TTL 為 241,920 分鐘（168 天）。Response 沒有 `expires_in`。

錯誤：

- HTTP 400 malformed / missing grant fields → `Lihi_Validation_Exception`
- HTTP 403 invalid JWT、拿 access token 當 refresh token、UUID 不符、token 已過期或已輪替、client 不存在、user 失效：`{ "result": false, "msg": "refresh token invalid" }` → `Lihi_Refresh_Token_Invalid_Exception`
- HTTP 429 → `Lihi_Rate_Limit_Exception`
- HTTP 5xx / network failure → `Lihi_Server_Exception`

在 protected-request fallback 中只要已嘗試 refresh，以上任何錯誤都會使外掛在持有 auth lock 時呼叫 `delete_if_uuid()`，條件式清除仍屬於該次 server-issued Login-session UUID 的 credentials，並顯示必須重新 Login 的 session error。這包含 HTTP 403、validation、HTTP 429、network / 5xx、malformed success response 與 guarded credential persistence failure。Cleanup 本身若失去 lock 或遇到 DB error，也不會覆蓋原 refresh failure；呼叫端仍收到要求重新 Login 的訊息。

---

## GET `/auth/verify-email`

使用者從 Register 驗證信點擊的 HTML flow；外掛不直接呼叫。Query parameter 是 `token`。成功時以 registration record 內在 Register request 階段快照的 country、IP 與 device 建立帳號，再回 verification-success HTML；不重新執行 GeoIP，也不採用點擊驗證連結時的 IP、User-Agent 或 device。缺失、過期、已使用或驗證失敗時回 404 HTML。這個 flow 不發 access / refresh token，也不會自動登入 WordPress 外掛。

---

## Credential storage and protected-request fallback

WordPress 保存：

- `lihi_auth_tokens`：單一 site-scoped、`autoload = no` option，值固定為 `{ email, uuid, access_token, refresh_token }`；任何缺欄、空 email / token，或 `uuid` 不符合 16–128 字元 `[A-Za-z0-9_-]` 都視為 disconnected。Email 在 Login AJAX 驗證後寫入，store 會 trim 並轉小寫；`uuid` 只 trim 且保留 server 回傳的大小寫。沒有獨立 email option；`lihi_email()` 從這個 bundle 讀值。
- `lihi_auth_tokens_lock`：`autoload = no` 的 20-second renewable DB auth lease；同一把 lease 包住完整 Login、Refresh、Logout、conditional cleanup 與 lifecycle transition。Acquire 使用 options-table `INSERT IGNORE`，只有 `$wpdb->query()` 嚴格回傳 integer `1` 才取得 ownership。Login 在兩段 remote auth calls之間及 persistence前先重驗 activation epoch，再以 byte-exact CAS續租；Refresh在 remote response回來後同樣先重驗 epoch，才於 validation / persistence前續租。Renew UPDATE成功但 direct read-back mismatch時，store會先 compare-delete exact renewed value才清除 local owner，避免留下只能等待TTL的orphan lease。
- `lihi_auth_epoch`：`autoload = no` 的 random activation generation，值為 32 random bytes 編碼成 64-character lowercase hex。每次成功 activation 都建立新值；缺失、格式錯誤或與 request captured generation 不同時，auth read / write fail closed。

`get()` 第一次直接查詢 epoch與 credential tuple後，在同一個 `Lihi_Token_Store` / PHP request內 memoize normalized結果；`get_fresh()` 強制 direct read並替換 memo。所有 Login、Refresh、conditional cleanup與protected workflow concurrency paths都使用 `get_fresh()`，因此仍能觀察其他 request剛完成的 rotation；一般 `lihi_email()` / `lihi_is_authenticated()` bootstrap probes則共用 memo，避免每頁重複兩次 direct queries。Direct query以 `CONCAT('x', option_value)` marker區分 missing row（SQL `NULL`）與 present empty row（`"x"`）。DB API不可用或 `$wpdb->last_error`非空時，store契約一律拋 `Lihi_Server_Exception`；但 bootstrap helper catches所有 store failures並降級為 disconnected，避免 include期間打掉整個 wp-admin。Service/AJAX執行路徑仍會正常回報錯誤。

`set()` 先要求 request epoch仍 current 且本 request仍為 lock owner，然後用單一 `INSERT ... SELECT ... ON DUPLICATE KEY UPDATE` 完成 guarded upsert。該 SQL 同時以 `CAST(option_value AS BINARY) = CAST(%s AS BINARY)` 精確比對 captured epoch 和 lock owner；guard 不符時 `SELECT` 不產生 row，因此 stale owner不能 insert或 overwrite tuple。Query 後再 direct-read raw serialized tuple並確認 exact match、epoch仍 current、lock仍 owned；guard與write由同一 SQL statement線性化。

`delete_if_access_token()` / `delete_if_uuid()` 也強制要求 current lock ownership。兩者 direct-read raw serialized tuple，以 normalize 後的資料判斷 predicate，但 CAS delete 使用原始 raw bytes與 `CAST(option_value AS BINARY)`，不重新 serialize normalized tuple。因此不同 key order、額外 key、case 差異及任何 intervening replacement都能被精確區分。Lease renew、release、stale-owner takeover、epoch disable與 failed-enable cleanup同樣 compare exact value。

Lock acquire 與 epoch enable 都直接執行 non-autoloaded `INSERT IGNORE`，並只將 affected rows 嚴格等於 integer `1` 視為成功。不使用 WordPress `add_option()`，因為其既有 row path 可能更新資料，不具嚴格 insert-only mutex 語意。Epoch insert 後以 direct DB read-back驗證；mismatch 時只清除自己剛插入的 exact epoch。

所有 successful direct writes/deletes 都 invalidates individual option key、`notoptions` 與 request credential memo。三個 auth options 都固定為 `autoload = no`，因此不清除無關的 site-wide `alloptions` cache。Unconditional credential `delete()` 要求 direct DELETE query成功，並再 direct-read確認 row 已不存在。Read / insert / update / delete 的 DB API缺失、`false` query result或 `$wpdb->last_error`都一致拋 `Lihi_Server_Exception`；`false`僅表示正常 insert contention、guard mismatch或已失去 ownership。Malformed/empty epoch row可由 disable exact-delete後重新 enable；malformed/empty、timestamp超前超過20秒或超過20秒未續租的 lease可 exact-delete後重新 acquire。Refresh waiter、Login lock acquisition與 Logout 最多等待18秒，涵蓋 client 的15秒 HTTP timeout及短暫 persistence margin；看到 lease missing或stale會提早停止。最終仍競爭失敗時回 distinct HTTP 409 authentication-busy message，不誤報 credential write failure。

Connected guard 要求 current epoch 加上完整 `{ email, uuid, access_token, refresh_token }` bundle。Register 不會寫入它。Logout 會清除 bundle。

Lifecycle 規則：

1. Activation callback 自行 `require_once` exception 與 TokenStore files，不依賴 normal admin bootstrap。完整 transition 先 disable epoch fence，再等待最長22秒取得 shared lock；lock內再次 disable、purge credentials、enable fresh random non-autoload epoch，最後 release。Login / Refresh 的 remote response回來後會先重驗 epoch，fence失效時不再續租或開始下一段 HTTP，因此 lifecycle只需排空目前一段最長15秒的 request；22秒大於20秒 lease TTL且低於常見30秒 PHP執行上限。每次啟用都從 disconnected state 開始。
2. Deactivation 使用同一個 self-contained loader與 transition；先 disable fence，lock內再次 disable、purge並驗證 epoch仍不存在，再 release。Uninstall直接 require相同 files並執行 disabled transition。
3. 任一 transition失敗時，best-effort fallback會分開嘗試 `disable()` 和 `delete()`，各自吞掉 cleanup error以避免 WordPress hook fatal。它不直接刪 foreign lock；epoch成功移除時，即使 credential delete失敗，殘留 tuple也不可使用。

Login 先 direct-DB 驗證 request captured epoch，取得 auth lock 後再驗證一次，才呼叫 `/auth/login`；每段 remote response回來後、續租或進入下一段 HTTP前再驗證 epoch。它跨 authorization-code exchange、response validation 與 atomic bundle write 全程持有 lock，且所有 exit path 都必須 release。Register 在遠端呼叫前驗證 epoch。Protected workflow 在讀取 bundle 前驗證 epoch，Refresh 進入時、取得 lock 後及 remote response回來後各再驗證一次。Logout 也先取得同一把 lock，避免與 in-flight Login 或 Refresh 交錯。

Settings Login / Register 是兩個真實的 POST forms，action 指向 WordPress `admin-ajax.php`，各自帶 hidden AJAX action 與 nonce；沒有 JavaScript 時仍走相同 PHP handlers。只控制顯示狀態的 `lihi_auth_tab` query value 在讀取時直接 unslash + sanitize，僅接受 `register`，無效或 non-scalar 值回到預設 Login；因為不改變 server state，所以不要求 nonce。Register password 的最低長度在 PHP 與 JavaScript 都直接以 raw value 的 Unicode code points 計算，要求至少 6 個，不在其中一端額外 trim。JavaScript local validation 以 `aria-invalid` / `aria-describedby` 將欄位連到 live status 並 focus 第一個錯誤欄位；Login / Logout / work-group switch 成功先保留訊息 2 秒再 reload，work-group switch 透過 reload 重新取得 profile 與 group-scoped page state；Register 成功仍維持 disconnected。Login / Register AJAX 會捕捉 unexpected `Throwable` 並回 generic service-unavailable response。在 `WP_DEBUG` 下，這兩條 credential-handling paths 也只記錄 exception class，不記錄 exception message，避免 message 夾帶呼叫參數或 password。

所有受保護 client methods 使用相同策略：

1. 以目前 access token 呼叫 API。
2. 只有可解析 JSON 的 HTTP 401 `{ "result": "failed", "msg": "Token expired ,please login again" }` 觸發 access fallback；malformed / non-JSON 401 fail closed 為 `Lihi_Server_Exception`。
3. Lock winner 重新讀取 tuple，並持續持有同一把 auth lock 完成 UUID + refresh token 的遠端 refresh、response UUID 驗證、email 保留與完整 bundle replacement；等待者輪詢並重用 winner 寫入的新 access token。
4. 原本的 protected endpoint 以 replacement access token 重試一次，不會無限重試。
5. 一旦 refresh 被嘗試，任何 `Throwable`（invalid token、validation / 429、network / 5xx、malformed success 或 guarded persistence failure）都在持鎖狀態以 `delete_if_uuid()` 嘗試清除仍屬於相同 server-issued Login-session UUID 的 raw tuple，並回 re-login session error。Cleanup 本身失敗也不覆蓋這個訊息；intervening replacement因 raw `BINARY` CAS 而不會被清掉。

HTTP 404 `user_not_found ,please login again`、HTTP 403 `User Invalid` 與 refresh 後重試仍回 HTTP 401 都是 terminal protected failure，不會再次 refresh；外掛改用 `delete_if_access_token()`，只在 tuple 仍含 rejected access token 時清除，因此保留另一 request 已成功輪替的 credentials。所有 HTTP 5xx 都在檢查 body message 前固定映射為 `Lihi_Server_Exception`，即使 5xx body 重用 `User Invalid` 或 `user_not_found` 也不會被當成 identity failure 並清除 credentials。直接由 protected endpoint 回傳的 HTTP 429、validation、network 與 5xx 不觸發 refresh；但若這些錯誤發生在已由 HTTP 401 啟動的 refresh 嘗試中，則套用上面的 UUID conditional-delete / re-login 規則。

---

## GET `/user/profile`

讀取目前 JWT 對應帳號的 profile。

Auth: bearer token required.

Response 200:
```json
{
  "result": true,
  "msg": "",
  "data": {
    "user_role": "admin",
    "group_name": "Marketing Team"
  }
}
```

- `user_role` 可能為 `null`。
- `group_name` 可能為 `null`；設定頁顯示為 `My Work Group`。
- Service 將缺失欄位 normalize為 `null`；`data` 非 object/array，或任一欄位不是 string / `null` 時拋 `Lihi_Server_Exception`，設定頁顯示暫時無法取得資料，不把未驗證值傳給 escaping functions。

---

## GET `/user/domain-options`

讀取建立短網址 modal 需要的選項。

Auth: bearer token required.

Response 200:
```json
{
  "result": true,
  "msg": "",
  "data": {
    "domains": [
      { "id": 123, "name": "wp-domain.example" },
      { "id": "redirect.lihidev.com", "name": "redirect.lihidev.com" }
    ],
    "utm_sources": ["facebook", "newsletter"],
    "utm_mediums": ["social", "email"]
  }
}
```

- `domains` 為建立 modal 的 redirect-domain options；外掛會正規化成 `{ value, label }`，送出時使用 `value`
- `utm_sources` / `utm_mediums` 是建立 modal 中 `utm_source` / `utm_medium` 下拉選單的 options；其他 UTM 欄位仍由使用者輸入
- 前端透過 `lihi_url_options` AJAX 一次取得 domains 與 UTM options，並快取 60 秒

---

## GET `/user/group-options`

讀取目前 server-issued WordPress client 可切換的工作群組，以及該 client 目前使用的 group ID。設定頁只有在使用者點擊 Switch、開啟 modal 後才透過 `lihi_group_options` AJAX 呼叫。

Auth: bearer token required.

Response 200:

```json
{
  "result": true,
  "msg": "",
  "data": {
    "groups": [
      { "id": null, "name": "My Group" },
      { "id": 42, "name": "Marketing Team" }
    ],
    "group_id": null
  }
}
```

- `groups` 必須至少包含目前選項。每個 `id` 是正整數或 `null`；`null` 代表主帳號未綁定 group 的個人工作群組。
- 每個 `name` 是 string 或 `null`。WordPress modal 對 `id: null` 固定顯示 `My Work Group`；非 null ID 若沒有名稱則顯示含 ID 的 unnamed fallback。
- `group_id` 是此 WordPress client 目前使用的正整數 ID或 `null`，且必須出現在 `groups`。外掛在回傳 browser 前正規化並驗證完整 response。

---

## POST `/user/switch-group`

只切換 bearer token 對應的 server-issued WordPress client，不改變其他 WordPress clients。切換後，同一 access token 的 profile、domain options 與 short-URL calls 都使用新群組；之後 refresh 也延續此 client 的目前群組。

Auth: bearer token required.

Body（切換到指定群組）:

```json
{ "group_id": 42 }
```

Body（切換到 ID 為 null 的個人工作群組）:

```json
{ "group_id": null }
```

`group_id` 欄位必須存在；值只能是 `null` 或正整數，且必須屬於 `GET /user/group-options` 回傳的可用選項。

Response 200:

```json
{
  "result": true,
  "msg": "",
  "data": {
    "group_id": 42
  }
}
```

外掛要求 response `group_id` 與 request 的 nullable ID 完全一致，否則 fail closed 為 `Lihi_Server_Exception`。

錯誤：

- HTTP 400 validation（缺少欄位、非整數或小於 1）→ `Lihi_Validation_Exception`
- HTTP 400 `{ "result": false, "msg": "group invalid" }`（群組不在 client 可用選項）→ `Lihi_Validation_Exception`

---

以下為全部受保護 endpoints（`/user/profile`、`/user/domain-options`、`/user/group-options`、`/user/switch-group`、`/passthrough/nonce`、`/site/find`、`/site/store`）共用錯誤映射與 fallback 行為。

**錯誤：帳號不可使用** → `Lihi_User_Invalid_Exception`
```json
{ "result": "failed", "msg": "user_not_found ,please login again" }
```
```json
{ "result": "failed", "msg": "User Invalid" }
```
HTTP 404 表示 client 對應 user 不存在；HTTP 403 表示帳號已刪除或停用。兩者都是 terminal failure：外掛不 refresh，並只清除仍匹配 rejected access token 的 credential tuple。

**錯誤：access token 缺少、格式 / type / claims 無效、過期，或 client 已失效（HTTP 401）** → `Lihi_Token_Invalid_Exception`

```json
{ "result": "failed", "msg": "Token expired ,please login again" }
```

這是唯一會觸發 access fallback 的 protected response，且 response 必須是可解析 JSON。Client 使用 refresh flow 取得 replacement access token，原 endpoint 重試一次；refresh 或第二次 protected call 仍失敗時，設定頁 / Short URL AJAX 顯示 login session expired。Malformed / non-JSON HTTP 401 視為 service failure，不 refresh。

**錯誤：rate limited（HTTP 429）** → `Lihi_Rate_Limit_Exception`

Rate limit 不觸發 refresh，也不刪除 credentials。

**其他 JSON failure / HTTP 5xx** → `Lihi_Server_Exception`
- 若 protected endpoint 回傳 JSON envelope 但 `result !== true`，且不屬於 user invalid / access-token invalid / validation error，外掛端會視為 lihi 服務錯誤，不會把 options 當空陣列或把短網址查詢當作不存在。

---

## POST `/passthrough/nonce`

建立 30 秒內有效且只能使用一次的 passthrough nonce，供瀏覽器 GET 到 `/passthrough/redirect`，讓 lihi-admin 建立 web session 後導向指定後台頁面或短網址查詢結果。這是 server-to-server JSON endpoint，已加入 `Lihi_Client` contract；真正建立 session 的 redirect endpoint 需由瀏覽器導向，不屬於 HTTP client method。

Auth: bearer token required.

Body:
```json
{
  "challenge": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
  "target": "https://example.com/demo-path?foo=bar"
}
```

- `challenge` 為瀏覽器產生 verifier 後計算的 `base64url(sha256(verifier))`，長度 43，供 redirect GET 時用 verifier 驗證。
- `target` 可省略或為空字串；也可為 admin-relative path（例如 `/myDomain`、`/profile#utm-setting`）或完整 `http(s)` URL。外掛 Edit button 會先查目前短網址，並把該短網址作為完整 URL `target`；Custom domain 入口會以 `/myDomain` 作為 `target`，UTM 管理入口會以 `/profile#utm-setting` 作為 `target`。
- `target` 最大長度 2048；`challenge` 必填。

Response 200:
```json
{
  "result": true,
  "msg": "",
  "data": {
    "nonce": "eyJhbGci..."
  }
}
```

**錯誤：欄位驗證失敗（HTTP 400）** → `Lihi_Validation_Exception`
```json
{ "result": false, "msg": { "target": ["The target may not be greater than 2048 characters."] } }
```

**錯誤：rate limited（HTTP 429）** → `Lihi_Rate_Limit_Exception`
由 route middleware `throttle:10,1` 控制：每分鐘 10 次。

受保護 auth / identity 錯誤與 access fallback 使用上方共用映射。

---

## GET `/passthrough/redirect`

瀏覽器 HTML/session flow。外掛 Edit button 先向 `POST /passthrough/nonce` 取得 nonce，再用瀏覽器導向此 endpoint 並帶上 query；成功時 lihi-admin 會消耗一次性 nonce、建立 web session cookie 並 redirect。

Query:
```json
{
  "nonce": "eyJhbGci...",
  "verifier": "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB"
}
```

- `verifier` 為瀏覽器在 Edit flow 開始時產生的 base64url random string；lihi-admin 會驗證 `base64url(sha256(verifier))` 是否等於 nonce payload 中的 `challenge`。

成功：
- `target` 空 → redirect `/admin`
- `target` 為 `/myDomain`、`/profile#utm-setting` 等 admin-relative path → redirect `/admin/myDomain`、`/admin/profile#utm-setting`
- `target` 為完整 URL → redirect `/admin?tag={target}`

此 endpoint 不是 `Lihi_Client` method，因為它的副作用是瀏覽器 web session。

---

## GET `/site/find`

查詢單一既有短網址。

Auth: bearer token required.

Query params: `type`, `type_id`

- `type` + `type_id` 合起來是 WordPress link 查詢條件，兩者皆必填
- `type_id` 需傳單一字串，不支援多筆 ID 或 comma-separated list
- 本外掛在呼叫時會將 `type` 串上網站本身的 host（格式 `"{type}:{host}"`，例如 `post:example.com`），以便同一 lihi 帳號下多個 WordPress 站台共用相同 `type_id` 時仍可區分

Response 200，有既有短網址:
```json
{
  "result": true,
  "msg": "",
  "data": {
    "site": "https://redirect.lihidev.com/abc"
  }
}
```

Response 200，無既有短網址:
```json
{
  "result": true,
  "msg": "",
  "data": {
    "site": ""
  }
}
```

- `site` 是短網址字串；空字串表示沒有既有短網址
- 此 endpoint 不回傳 domains 或 UTM options；建立 modal 選項請使用 `GET /user/domain-options`

**錯誤：欄位驗證失敗（HTTP 400）** → `Lihi_Validation_Exception`
```json
{ "result": false, "msg": { "type": ["The type field is required."], "type_id": ["The type id field is required."] } }
```

受保護 auth / identity 錯誤與 access fallback 使用上方共用映射。

---

## POST `/site/store`

建立新的短網址 site record。

Auth: bearer token required.

伺服器端要求 `domain`、`urls`、`type` 必填；`type_id` 可選但傳字串。

Body:
```json
{
  "domain": "redirect.lihidev.com",
  "urls": ["https://example.com/?p=42&utm_source=newsletter&utm_medium=email&utm_campaign=spring-sale"],
  "type": "post:example.com",
  "type_id": "42",
  "tags": "campaign,wordpress"
}
```

本外掛送出的 `type` 會帶上 WordPress host（格式 `"{type}:{host}"`），與 `GET /site/find` 的查詢條件一致。建立 modal 沒有預設已選 tags；只有使用者實際加入的 tags 會去重後以逗號分隔字串送出。`wordpress`、WordPress host 與原始 `type` 只作為推薦按鈕，點擊後才加入。

建立 modal 透過 `lihi_url_options` / `GET /user/domain-options` 載入 domains、`utm_sources` 與 `utm_mediums`，前端快取 60 秒。Attachment modal 隱藏 UTM controls 並提交空值；後端忽略空白 UTM。有效 UTM 只附加到 destination URL query string，不以獨立 `utm` object 傳給 lihi API。Create 只在 WordPress 端要求 non-empty domain，最終 domain membership 由 lihi API 驗證。

Custom domain 與 UTM option management 會在確認後產生 browser verifier / challenge，呼叫 `lihi_passthrough_nonce`，再以 nonce + verifier 開啟 `/passthrough/redirect`。Targets 分別是 `/myDomain` 與 `/profile#utm-setting`。

Short URL UI 只在完整 `{ email, uuid, access_token, refresh_token }` bundle 存在時註冊。PHP 輸出空的 `data-lihi-container`；前端建立 buttons。現行四個 Short URL / passthrough AJAX actions 是 `lihi_url_options`、`lihi_create_url`、`lihi_copy_url`、`lihi_passthrough_nonce`；設定頁另有 `lihi_group_options` 與 `lihi_switch_group`。

Find 命中或 Create 成功後，外掛寫入 `lihi_already = 1` 並將 button 顯示為 `Copy`；具備 `manage_options` 的使用者另看到 `Edit`。Clipboard 被阻擋時以 prompt 提供手動複製。Copy 仍用 `GET /site/find` 驗證 upstream URL；missing 時寫入 `lihi_already = 0`，回 HTTP 410 / `lihi_missing`，並在確認後重新開啟 Create modal。Edit 先用 `lihi_copy_url` 取得現存短網址，再將該 URL 作為 `lihi_passthrough_nonce` target；missing 使用相同 410 flow。

Response:
```json
{
  "result": true,
  "data": {
    "id": 456,
    "domain_name": "redirect.lihidev.com",
    "short_url": "https://redirect.lihidev.com/xyz",
    "site_urls": [],
    "wordpress_link": { "type": "post:example.com", "type_id": "42" }
  }
}
```

**錯誤：欄位缺失（HTTP 400）**
```json
{
  "result": false,
  "msg": {
    "domain": ["The domain field is required."],
    "urls":   ["The urls field is required."],
    "type":   ["The type field is required."]
  }
}
```

**錯誤：需要升級或續約（HTTP 400）**
```json
{
  "result": false,
  "msg": "need_upgrade"
}
```

**錯誤：其他建立失敗（HTTP 400）**
```json
{
  "result": false,
  "msg": "site_create_fail"
}
```

WordPress endpoint 不回傳 `type`。需要升級或續約的建立失敗使用穩定的 `need_upgrade` 訊息；其他可預期的建立失敗使用 `site_create_fail`。

WordPress client 將 `need_upgrade` 映射成 `Lihi_Need_Upgrade_Exception`，Short URL AJAX 再回傳 plugin-owned gettext message：

```json
{ "code": "need_upgrade", "message": "..." }
```

`site_create_fail` 和其他 HTTP 400 維持 `Lihi_Validation_Exception`，走共用 validation error mapping。HTTP 5xx 維持 `Lihi_Server_Exception`。這些 HTTP 400 都不觸發 access-token refresh。

受保護 auth / identity 錯誤與 access fallback 使用上方共用映射。
