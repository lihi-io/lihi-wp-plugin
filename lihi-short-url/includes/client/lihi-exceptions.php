<?php
namespace Lihi\ShortUrl;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Base exception for all lihi API errors. */
class Lihi_Exception extends \RuntimeException {}

/** Authorization rejected — e.g. lihi API returns 403 "email not verified". */
class Lihi_Auth_Exception extends Lihi_Exception {}

/** lihi account password verification failed. */
class Lihi_Email_Or_Password_Invalid_Exception extends Lihi_Auth_Exception {}

/** The requested lihi account does not exist. */
class Lihi_Account_Not_Found_Exception extends Lihi_Auth_Exception {}

/** Registration was attempted for an existing lihi account. */
class Lihi_Account_Already_Exists_Exception extends Lihi_Exception {}

/** lihi user is invalid server-side. */
class Lihi_User_Invalid_Exception extends Lihi_Auth_Exception {}

/** The short-lived PKCE authorization code is invalid or has expired. */
class Lihi_Authorization_Code_Invalid_Exception extends Lihi_Auth_Exception {}

/** The persisted refresh token is invalid, expired, or has been rotated. */
class Lihi_Refresh_Token_Invalid_Exception extends Lihi_Auth_Exception {}

/** HTTP 400 — required fields missing or invalid. */
class Lihi_Validation_Exception extends Lihi_Exception {}

/** HTTP 404 HTML — resource not found. */
class Lihi_Not_Found_Exception extends Lihi_Exception {}

/** HTTP 429 — the upstream lihi request rate limit was exceeded. */
class Lihi_Rate_Limit_Exception extends Lihi_Exception {}

/** HTTP 5xx HTML or other unrecoverable server error. */
class Lihi_Server_Exception extends Lihi_Exception {}

/** Another request currently owns the Login / Refresh authentication lease. */
class Lihi_Authentication_Busy_Exception extends Lihi_Server_Exception {}

/**
 * Token is missing, expired, or has been revoked server-side.
 *
 * The current Wordpress API reports this as HTTP 401. It intentionally does
 * not extend Lihi_Server_Exception: callers may refresh the access token only
 * for this condition, never for an upstream outage.
 */
class Lihi_Token_Invalid_Exception extends Lihi_Auth_Exception {}
