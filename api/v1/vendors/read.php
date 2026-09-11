<?php

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

// Specific vendor via their ID (single)
if (isset($_GET['vendor_id'])) {
    $id = intval($_GET['vendor_id']);
    $sql = mysqli_query($mysqli, "SELECT * FROM vendors WHERE vendor_id = '$id' AND 1=1 " . apiClientScopeSql('vendor_client_id') . "");

} elseif (isset($_GET['vendor_name'])) {
    // Specific vendor via name (e.g. finding an existing "Microsoft" vendor before creating a duplicate)
    $name = mysqli_real_escape_string($mysqli, $_GET['vendor_name']);
    $sql = mysqli_query($mysqli, "SELECT * FROM vendors WHERE vendor_name = '$name' AND vendor_client_id LIKE '$client_id' ORDER BY vendor_id LIMIT $limit OFFSET $offset");

} else {
    // All Vendors (by client ID or all in general if key permits)
    $sql = mysqli_query($mysqli, "SELECT * FROM vendors WHERE 1=1 " . apiClientScopeSql('vendor_client_id') . " ORDER BY vendor_id LIMIT $limit OFFSET $offset");
}

// Output
require_once "../read_output.php";

