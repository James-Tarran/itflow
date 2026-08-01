<?php

/*
 * API - technicians/read.php
 * Lists technicians (agent users) - e.g. for an external ticket
 * auto-assignment automation to pick a target user_id from.
 *
 * GET Parameters:
 *   api_key (required)
 *   user_id (optional) - a specific technician by ID
 *   limit / offset (optional) - pagination
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

if (isset($_GET['user_id'])) {
    $id = intval($_GET['user_id']);
    $sql = mysqli_query($mysqli, "SELECT user_id, user_name, user_email, user_status FROM users WHERE user_id = $id AND user_type = 1 AND user_archived_at IS NULL LIMIT 1");
} else {
    $sql = mysqli_query($mysqli, "SELECT user_id, user_name, user_email, user_status FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC LIMIT $limit OFFSET $offset");
}

// Output
require_once "../read_output.php";
