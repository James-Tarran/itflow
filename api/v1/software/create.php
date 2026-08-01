<?php

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse Info
$software_row = false; // Creation, not an update
require_once 'software_model.php';

// Default
$insert_id = false;

if (!empty($name) && !empty($client_id)) {

    $purchase_sql = !empty($purchase) ? "'" . mysqli_real_escape_string($mysqli, $purchase) . "'" : 'NULL';
    $expire_sql = !empty($expire) ? "'" . mysqli_real_escape_string($mysqli, $expire) . "'" : 'NULL';

    $insert_sql = mysqli_query($mysqli, "INSERT INTO software SET software_name = '$name', software_description = '$description', software_version = '$version', software_type = '$type', software_license_type = '$license_type', software_key = '$key', software_seats = $seats, software_purchase_reference = '$purchase_reference', software_purchase = $purchase_sql, software_expire = $expire_sql, software_notes = '$notes', software_vendor_id = $vendor_id, software_client_id = $client_id");

    // Check insert & get insert ID
    if ($insert_sql) {
        $insert_id = mysqli_insert_id($mysqli);

        // Logging
        logAction("Software", "Create", "$name via API ($api_key_name)", $client_id, $insert_id);
        logAction("API", "Success", "Created software $name via API ($api_key_name)", $client_id);
    }

}

// Output
require_once '../create_output.php';
