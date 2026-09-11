<?php

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse Info
$software_id = intval($_POST['software_id'] ?? 0);

// Default
$update_count = false;

if (!empty($software_id)) {

    $software_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM software WHERE software_id = '$software_id' AND software_client_id = $client_id LIMIT 1"));

    // Variable assignment from POST - assigning the current database value if a value is not provided
    require_once 'software_model.php';

    $purchase_sql = !empty($purchase) ? "'" . mysqli_real_escape_string($mysqli, $purchase) . "'" : 'NULL';
    $expire_sql = !empty($expire) ? "'" . mysqli_real_escape_string($mysqli, $expire) . "'" : 'NULL';

    $update_sql = mysqli_query($mysqli, "UPDATE software SET software_name = '$name', software_description = '$description', software_version = '$version', software_type = '$type', software_license_type = '$license_type', software_key = '$key', software_seats = $seats, software_purchase_reference = '$purchase_reference', software_purchase = $purchase_sql, software_expire = $expire_sql, software_notes = '$notes', software_vendor_id = $vendor_id WHERE software_id = $software_id LIMIT 1");

    // Check insert & get insert ID
    if ($update_sql) {
        $update_count = mysqli_affected_rows($mysqli);

        // Logging
        logAudit("Software", "Edit", "$name via API ($api_key_name)", $client_id, $software_id);
        logAudit("API", "Success", "Edited software $name via API ($api_key_name)", $client_id);
    }
}

// Output
require_once '../update_output.php';
