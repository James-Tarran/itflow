<?php

require_once '../validate_api_key.php';

require_once '../require_get_method.php';


// Specific ticket via ID (single)
if (isset($_GET['ticket_id'])) {
    $id = intval($_GET['ticket_id']);
    $sql = mysqli_query(
        $mysqli,
        "SELECT tickets.*, ticket_statuses.ticket_status_name, contacts.contact_name, contacts.contact_email
        FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        WHERE ticket_id = '$id' AND ticket_client_id LIKE '$client_id'"
    );

} else {
    // All tickets (by client ID if given, or all in general if key permits)
    // Optional filters: ticket_status_name (e.g. a custom status like "Waiting
    // Customer Response"), ticket_assigned_to (e.g. 0 to find unassigned tickets)
    $where = ["ticket_client_id LIKE '$client_id'", "ticket_archived_at IS NULL"];

    if (isset($_GET['ticket_status_name'])) {
        $status_name = mysqli_real_escape_string($mysqli, $_GET['ticket_status_name']);
        $where[] = "ticket_statuses.ticket_status_name = '$status_name'";
    }

    if (isset($_GET['ticket_assigned_to'])) {
        $assigned_to = intval($_GET['ticket_assigned_to']);
        $where[] = "ticket_assigned_to = $assigned_to";
    }

    $where_sql = implode(' AND ', $where);

    $sql = mysqli_query($mysqli, "SELECT tickets.*, ticket_statuses.ticket_status_name, contacts.contact_name, contacts.contact_email
        FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        WHERE $where_sql
        ORDER BY ticket_id LIMIT $limit OFFSET $offset");
}

// Output
require_once "../read_output.php";

