<?php

/*
 * ITFlow - Database update to version 2.7.9 (from 2.7.8)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

    // Microsoft Graph / OAuth mail support.
    //
    // config_mail_oauth_access_token_provider tracks which provider
    // ('google_oauth' | 'microsoft_oauth' | 'microsoft_graph') the currently
    // cached config_mail_oauth_access_token was issued for. Access tokens under
    // the Microsoft v2.0 endpoint are resource-specific (Outlook and Graph have
    // different audiences), so the cached token must never be reused across
    // providers even though they share the same OAuth app credentials.
    //
    // config_mail_oauth_consented_resources tracks which Microsoft OAuth
    // resources ('outlook' and/or 'graph', comma separated) have actually been
    // consented via the Connect flow. Azure AD only allows requesting one
    // resource per authorize/token call (AADSTS28000), so Sending (Graph) and
    // Receiving (Outlook IMAP) each need their own Connect click - without this
    // the flow has no way to know Graph is already connected and would keep
    // re-requesting Graph consent forever instead of moving on to Outlook.
    //
    // Both columns are added conditionally: installs that ran the older inline
    // 2.4.5 / 2.4.6 updates already have them.

    $itflow_oauth_columns = [
        'config_mail_oauth_access_token_provider' => "ADD `config_mail_oauth_access_token_provider` VARCHAR(50) NULL DEFAULT NULL AFTER `config_mail_oauth_access_token_expires_at`",
        'config_mail_oauth_consented_resources'   => "ADD `config_mail_oauth_consented_resources` VARCHAR(50) NULL DEFAULT NULL AFTER `config_mail_oauth_access_token_provider`",
    ];

    foreach ($itflow_oauth_columns as $itflow_column => $itflow_alter) {
        $itflow_column_exists = mysqli_query($mysqli, "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'settings'
            AND COLUMN_NAME = '" . escapeSql($itflow_column) . "'
            LIMIT 1");

        if (!$itflow_column_exists || mysqli_num_rows($itflow_column_exists) === 0) {
            mysqli_query($mysqli, "ALTER TABLE `settings` $itflow_alter");
        }
    }

    unset($itflow_oauth_columns, $itflow_column, $itflow_alter, $itflow_column_exists);

    // --- Reconciliation for forks that used 2.4.5 / 2.4.6 for their own changes ---
    //
    // The two columns above originally shipped as inline updates that claimed
    // database versions 2.4.5 and 2.4.6. Upstream uses those same two version
    // numbers for entirely different migrations, so an install that ran the fork's
    // numbering is recorded as "2.4.6" while never having applied upstream's
    // 2.4.5.php (payment_providers fee columns) or 2.4.6.php
    // (user_client_permissions.permission_type) - the runner only applies files
    // ABOVE the current version, so both were skipped for good.
    //
    // Reapply them here, conditionally. On an install that came up through
    // upstream's numbering this whole block is a no-op.

    $itflow_fee_columns_exist = mysqli_query($mysqli, "SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'payment_providers'
        AND COLUMN_NAME = 'payment_provider_expense_percentage_fee'
        LIMIT 1");

    if ($itflow_fee_columns_exist && mysqli_num_rows($itflow_fee_columns_exist) > 0) {
        mysqli_query($mysqli, "ALTER TABLE `payment_providers` DROP `payment_provider_expense_percentage_fee`, DROP `payment_provider_expense_flat_fee`");
    }

    $itflow_permission_type_exists = mysqli_query($mysqli, "SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'user_client_permissions'
        AND COLUMN_NAME = 'permission_type'
        LIMIT 1");

    if (!$itflow_permission_type_exists || mysqli_num_rows($itflow_permission_type_exists) === 0) {
        mysqli_query($mysqli, "ALTER TABLE `user_client_permissions` ADD COLUMN `permission_type` ENUM('allow','deny') NOT NULL DEFAULT 'allow'");
    }

    unset($itflow_fee_columns_exist, $itflow_permission_type_exists);
