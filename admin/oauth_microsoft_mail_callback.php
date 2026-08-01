<?php

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

$settings_mail_path = '/admin/settings_mail.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    flash_alert("Admin access required.", 'error');
    redirect($settings_mail_path);
}

$state = sanitizeInput($_GET['state'] ?? '');
$code = $_GET['code'] ?? '';
$error = sanitizeInput($_GET['error'] ?? '');
$error_description = sanitizeInput($_GET['error_description'] ?? '');

$session_state = $_SESSION['mail_oauth_state'] ?? '';
$session_state_expires = intval($_SESSION['mail_oauth_state_expires_at'] ?? 0);
$resource = $_SESSION['mail_oauth_resource'] ?? 'outlook';

unset($_SESSION['mail_oauth_state'], $_SESSION['mail_oauth_state_expires_at'], $_SESSION['mail_oauth_resource']);

if (!empty($error)) {
    $msg = "Microsoft OAuth authorization failed: $error";
    if (!empty($error_description)) {
        $msg .= " ($error_description)";
    }

    flash_alert($msg, 'error');
    redirect($settings_mail_path);
}

if (empty($state) || empty($code) || empty($session_state) || !hash_equals($session_state, $state) || time() > $session_state_expires) {
    flash_alert("Microsoft OAuth callback validation failed. Please try connecting again.", 'error');
    redirect($settings_mail_path);
}

if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($config_mail_oauth_tenant_id)) {
    flash_alert("Microsoft OAuth settings are incomplete. Please fill Client ID, Client Secret, and Tenant ID.", 'error');
    redirect($settings_mail_path);
}

if (defined('BASE_URL') && !empty(BASE_URL)) {
    $base_url = rtrim((string) BASE_URL, '/');
} else {
    $base_url = 'https://' . rtrim((string) $config_base_url, '/');
}

$redirect_uri = $base_url . '/admin/oauth_microsoft_mail_callback.php';
$token_url = 'https://login.microsoftonline.com/' . rawurlencode($config_mail_oauth_tenant_id) . '/oauth2/v2.0/token';

// Must mirror exactly one resource - Azure AD rejects a scope spanning both
// https://outlook.office.com and https://graph.microsoft.com in one request
// (AADSTS28000), which is why the Connect click already picked a single resource.
$scope = $resource === 'graph'
    ? 'offline_access openid profile https://graph.microsoft.com/Mail.Send'
    : 'offline_access openid profile https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send';

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => $config_mail_oauth_client_id,
    'client_secret' => $config_mail_oauth_client_secret,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirect_uri,
    'scope' => $scope,
], '', '&'));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$raw_body = curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw_body === false || $http_code < 200 || $http_code >= 300) {
    // Surface Azure AD's actual error/error_description (e.g. AADSTS...) instead of
    // just the bare HTTP status - that's the only way to tell invalid_grant (code
    // reused/expired), invalid_scope (permission not added to the app registration),
    // and redirect_uri mismatch apart.
    $reason = "HTTP $http_code";
    $error_json = is_string($raw_body) ? json_decode($raw_body, true) : null;
    if (is_array($error_json) && !empty($error_json['error'])) {
        $reason = $error_json['error'];
        if (!empty($error_json['error_description'])) {
            $reason .= ': ' . $error_json['error_description'];
        }
    } elseif (!empty($curl_err)) {
        $reason = $curl_err;
    }

    flash_alert("Microsoft OAuth token exchange failed: " . htmlspecialchars(substr($reason, 0, 500)), 'error');
    redirect($settings_mail_path);
}

$json = json_decode($raw_body, true);
if (!is_array($json) || empty($json['refresh_token']) || empty($json['access_token'])) {
    flash_alert("Microsoft OAuth token exchange failed: refresh token or access token missing.", 'error');
    redirect($settings_mail_path);
}

$refresh_token = (string) $json['refresh_token'];
$access_token = (string) $json['access_token'];
$expires_at = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));

$refresh_token_esc = mysqli_real_escape_string($mysqli, $refresh_token);
$access_token_esc = mysqli_real_escape_string($mysqli, $access_token);
$expires_at_esc = mysqli_real_escape_string($mysqli, $expires_at);

// Only default a provider to the Microsoft OAuth family if it wasn't already
// set to one - otherwise this would silently switch e.g. a deliberately-chosen
// 'microsoft_graph' Sending provider back to SMTP OAuth (or vice versa). Only
// the fields relevant to the resource actually just consented are touched -
// a Graph connect grants Mail.Send only, never IMAP, so it never defaults IMAP.
$ms_oauth_family = ['microsoft_oauth', 'microsoft_graph'];
$provider_sql = '';
if ($resource === 'graph') {
    if (!in_array($config_smtp_provider, $ms_oauth_family, true)) {
        $provider_sql .= ", config_smtp_provider = 'microsoft_graph'";
    }
} else {
    if (!in_array($config_imap_provider, $ms_oauth_family, true)) {
        $provider_sql .= ", config_imap_provider = 'microsoft_oauth'";
    }
    if (!in_array($config_smtp_provider, $ms_oauth_family, true)) {
        $provider_sql .= ", config_smtp_provider = 'microsoft_oauth'";
    }
}

// The token audience always matches the single resource just requested, so the
// cache marker can be trusted directly - no more guessing which resource this
// exchange returned.
$access_token_provider = $resource === 'graph' ? 'microsoft_graph' : 'microsoft_oauth';

mysqli_query($mysqli, "UPDATE settings SET
    config_mail_oauth_refresh_token = '$refresh_token_esc',
    config_mail_oauth_access_token = '$access_token_esc',
    config_mail_oauth_access_token_expires_at = '$expires_at_esc',
    config_mail_oauth_access_token_provider = '$access_token_provider'
    $provider_sql
    WHERE company_id = 1
");

logAction("Settings", "Edit", "$session_name completed Microsoft OAuth connect flow for mail settings ($resource)");

$success_msg = "Microsoft OAuth connected successfully ($resource). Token expires at $expires_at.";

// Azure AD only allows one resource per Connect click (AADSTS28000), so if both
// Outlook (IMAP/SMTP) and Graph are configured, the other one still needs its own click.
$needs_outlook = ($config_imap_provider === 'microsoft_oauth' || $config_smtp_provider === 'microsoft_oauth');
$needs_graph = ($config_smtp_provider === 'microsoft_graph');
if ($resource === 'graph' && $needs_outlook) {
    $success_msg .= " Click Connect again to also authorize Outlook IMAP/SMTP.";
} elseif ($resource === 'outlook' && $needs_graph) {
    $success_msg .= " Click Connect again to also authorize Graph (Sending).";
}

flash_alert($success_msg);
redirect($settings_mail_path);
