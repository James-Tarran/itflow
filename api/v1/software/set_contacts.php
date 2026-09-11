<?php

/*
 * API - software/set_contacts.php
 * Replaces the full set of contacts a software/licence record is assigned to,
 * mirroring what agent/post/software.php does when the Software edit form is
 * saved: delete every existing link, then insert the posted set.
 *
 * Replace rather than add-only, because a sync has to be able to take a licence
 * away. contacts/link_software.php is INSERT IGNORE and can only ever add, so a
 * user who loses a licence in Microsoft 365 keeps it in ITFlow for good. Sending
 * the whole set also means the caller needs no read-modify-write: whatever it
 * posts becomes the truth, so the result converges from any prior state.
 *
 * POST Parameters:
 *   api_key     (required)
 *   client_id   (required - the client the software record belongs to)
 *   software_id (required)
 *   contact_ids (comma separated contact IDs; omit, or send empty, to clear all)
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

$software_id = intval($_POST['software_id'] ?? 0);

$return_arr = array();

if (empty($software_id)) {
    $return_arr['success'] = "False";
    $return_arr['message'] = "software_id is required.";
    echo json_encode($return_arr);
    exit();
}

// Not scoped in SQL: require_post_method.php only overrides $client_id from a posted
// client_id, so scoping the lookup on it would match nothing when the caller omits it
// (the 2.4.7 migration dropped api_key_client_id, so it no longer comes from the key).
// The client is checked in PHP below instead.
$software_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT software_name, software_client_id FROM software WHERE software_id = $software_id LIMIT 1"));

$software_client_id = intval($software_row['software_client_id'] ?? 0);

if (!$software_row || ($client_id != 0 && $software_client_id != $client_id)) {
    $return_arr['success'] = "False";
    $return_arr['message'] = "No software with that ID for this client.";

    logApp("API", "Error", "Set license assignments denied for software $software_id via API key " . escapeSql($api_key_name) . " from IP " . escapeSql(getIP()));

    echo json_encode($return_arr);
    exit();
}

// Only contacts belonging to the same client may be linked. Without this check an API
// caller could attach one client's contact to another client's licence just by posting
// the ID. Archived contacts are rejected too - linking one would resurrect it in the
// licence view while it stays hidden everywhere else.
$valid_contact_ids = array();
foreach (explode(',', (string)($_POST['contact_ids'] ?? '')) as $requested_id) {
    $contact_id = intval(trim($requested_id));
    if (empty($contact_id)) {
        continue;
    }
    $contact_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_id = $contact_id AND contact_client_id = $software_client_id AND contact_archived_at IS NULL LIMIT 1"));
    if ($contact_row) {
        // Keyed by ID so a repeated contact is inserted once
        $valid_contact_ids[$contact_id] = $contact_id;
    }
}

// Snapshot first, so the response and the audit log describe what actually changed
// rather than reporting an unchanged replace as an edit
$before = array();
$before_sql = mysqli_query($mysqli, "SELECT contact_id FROM software_contacts WHERE software_id = $software_id");
while ($before_row = mysqli_fetch_assoc($before_sql)) {
    $before[] = intval($before_row['contact_id']);
}

mysqli_query($mysqli, "DELETE FROM software_contacts WHERE software_id = $software_id");

foreach ($valid_contact_ids as $contact_id) {
    mysqli_query($mysqli, "INSERT INTO software_contacts SET software_id = $software_id, contact_id = $contact_id");
}

$after = array_values($valid_contact_ids);
sort($before);
sort($after);

$added = array_values(array_diff($after, $before));
$removed = array_values(array_diff($before, $after));

if ($added || $removed) {
    $software_name = escapeSql($software_row['software_name']);
    $added_count = count($added);
    $removed_count = count($removed);
    logAudit("Software", "Edit", "$software_name license assignments set via API ($api_key_name): $added_count added, $removed_count removed", $software_client_id, $software_id);
    logAudit("API", "Success", "Set license assignments for $software_name via API ($api_key_name)", $software_client_id, $software_id);
}

// Reported explicitly rather than via update_output.php, which treats a zero row count
// as failure. Clearing the last contact off a licence is a valid, successful outcome.
$return_arr['success'] = "True";
$return_arr['count'] = count($after);
$return_arr['added'] = $added;
$return_arr['removed'] = $removed;
$return_arr['changed'] = ($added || $removed) ? "True" : "False";

echo json_encode($return_arr);
exit();
