<?php

// Variable assignment from POST (or: blank/from DB if updating)

if (isset($_POST['software_name'])) {
    $name = sanitizeInput($_POST['software_name']);
} elseif ($software_row) {
    $name = mysqli_real_escape_string($mysqli, $software_row['software_name']);
} else {
    $name = '';
}

if (isset($_POST['software_description'])) {
    $description = sanitizeInput($_POST['software_description']);
} elseif ($software_row) {
    $description = mysqli_real_escape_string($mysqli, $software_row['software_description']);
} else {
    $description = '';
}

if (isset($_POST['software_version'])) {
    $version = sanitizeInput($_POST['software_version']);
} elseif ($software_row) {
    $version = mysqli_real_escape_string($mysqli, $software_row['software_version']);
} else {
    $version = '';
}

if (isset($_POST['software_type'])) {
    $type = sanitizeInput($_POST['software_type']);
} elseif ($software_row) {
    $type = mysqli_real_escape_string($mysqli, $software_row['software_type']);
} else {
    $type = '';
}

if (isset($_POST['software_license_type'])) {
    $license_type = sanitizeInput($_POST['software_license_type']);
} elseif ($software_row) {
    $license_type = mysqli_real_escape_string($mysqli, $software_row['software_license_type']);
} else {
    $license_type = '';
}

if (isset($_POST['software_key'])) {
    $key = sanitizeInput($_POST['software_key']);
} elseif ($software_row) {
    $key = mysqli_real_escape_string($mysqli, $software_row['software_key']);
} else {
    $key = '';
}

if (isset($_POST['software_seats'])) {
    $seats = intval($_POST['software_seats']);
} elseif ($software_row) {
    $seats = intval($software_row['software_seats']);
} else {
    $seats = 0;
}

if (isset($_POST['software_purchase_reference'])) {
    $purchase_reference = sanitizeInput($_POST['software_purchase_reference']);
} elseif ($software_row) {
    $purchase_reference = mysqli_real_escape_string($mysqli, $software_row['software_purchase_reference']);
} else {
    $purchase_reference = '';
}

if (isset($_POST['software_purchase'])) {
    $purchase = sanitizeInput($_POST['software_purchase']);
} elseif ($software_row) {
    $purchase = $software_row['software_purchase'];
} else {
    $purchase = '';
}

if (isset($_POST['software_expire'])) {
    $expire = sanitizeInput($_POST['software_expire']);
} elseif ($software_row) {
    $expire = $software_row['software_expire'];
} else {
    $expire = '';
}

if (isset($_POST['software_notes'])) {
    $notes = sanitizeInput($_POST['software_notes']);
} elseif ($software_row) {
    $notes = mysqli_real_escape_string($mysqli, $software_row['software_notes']);
} else {
    $notes = '';
}

if (isset($_POST['software_vendor_id'])) {
    $vendor_id = intval($_POST['software_vendor_id']);
} elseif ($software_row) {
    $vendor_id = intval($software_row['software_vendor_id']);
} else {
    $vendor_id = 0;
}
