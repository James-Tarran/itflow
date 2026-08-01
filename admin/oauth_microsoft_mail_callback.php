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

unset($_SESSION['mail_oauth_state'], $_SESSION['mail_oauth_state_expires_at']);

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
$scope = 'offline_access openid profile https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send https://graph.microsoft.com/Mail.Send';

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

// Only default a provider to 'microsoft_oauth' if it wasn't already set to a
// Microsoft OAuth family value - otherwise this would silently switch e.g. a
// deliberately-chosen 'microsoft_graph' Sending provider back to SMTP OAuth.
$ms_oauth_family = ['microsoft_oauth', 'microsoft_graph'];
$provider_sql = '';
if (!in_array($config_imap_provider, $ms_oauth_family, true)) {
    $provider_sql .= ", config_imap_provider = 'microsoft_oauth'";
}
if (!in_array($config_smtp_provider, $ms_oauth_family, true)) {
    $provider_sql .= ", config_smtp_provider = 'microsoft_oauth'";
}

// The requested scope list puts the Outlook resource (IMAP.AccessAsUser.All)
// first, so per Microsoft's v2.0 endpoint rules this initial exchange always
// returns an Outlook-audience token, never a Graph one - even when Sending is
// configured for Graph. Mark it as such so it's never handed to the Graph API;
// the Graph sender will transparently refresh a correctly-scoped token on its
// first use instead of reusing this one.
mysqli_query($mysqli, "UPDATE settings SET
    config_mail_oauth_refresh_token = '$refresh_token_esc',
    config_mail_oauth_access_token = '$access_token_esc',
    config_mail_oauth_access_token_expires_at = '$expires_at_esc',
    config_mail_oauth_access_token_provider = 'microsoft_oauth'
    $provider_sql
    WHERE company_id = 1
");

logAction("Settings", "Edit", "$session_name completed Microsoft OAuth connect flow for mail settings");
flash_alert("Microsoft OAuth connected successfully. Token expires at $expires_at.");
redirect($settings_mail_path);
