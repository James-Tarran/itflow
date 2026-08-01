<?php

/*
 * API - contacts/link_software.php
 * Links a software/license record to a contact (software_contacts junction
 * table), mirroring agent/post/contact.php's link_software_to_contact action.
 * Idempotent - linking an already-linked pair is a no-op, not an error.
 *
 * POST Parameters:
 *   api_key (required)
 *   contact_id (required)
 *   software_id (required)
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

$contact_id = intval($_POST['contact_id'] ?? 0);
$software_id = intval($_POST['software_id'] ?? 0);

$update_count = false;

if (!empty($contact_id) && !empty($software_id)) {

    $software_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT software_name, software_client_id FROM software WHERE software_id = $software_id LIMIT 1"));
    $contact_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_name, contact_client_id FROM contacts WHERE contact_id = $contact_id LIMIT 1"));

    $software_client_id = intval($software_row['software_client_id'] ?? 0);
    $contact_client_id = intval($contact_row['contact_client_id'] ?? 0);

    // Both records must belong to the same client, and (for a client-restricted key) to the key's client
    if ($software_row && $contact_row && $software_client_id === $contact_client_id
        && ($client_id == 0 || $software_client_id == $client_id)) {

        $software_name = sanitizeInput($software_row['software_name']);
        $contact_name = sanitizeInput($contact_row['contact_name']);

        // INSERT IGNORE: (software_id, contact_id) is the primary key, so re-linking an
        // already-linked pair (e.g. a repeated daily sync) is a no-op, not an error.
        $link_sql = mysqli_query($mysqli, "INSERT IGNORE INTO software_contacts SET contact_id = $contact_id, software_id = $software_id");

        if ($link_sql) {
            $update_count = 1;

            logAction("Software", "Link", "$software_name linked to contact $contact_name via API ($api_key_name)", $software_client_id, $software_id);
            logAction("API", "Success", "Linked software $software_name to contact $contact_name via API ($api_key_name)", $software_client_id);
        }
    }
}

// Output
require_once '../update_output.php';
