<?php

/*
 * API - tickets/update.php
 * Generic partial-update endpoint for a ticket's fields (status, assigned
 * technician, priority, subject, etc.) - only fields present in the POST
 * body are changed, everything else keeps its current value. See
 * ticket_model.php (shared with create.php) for the full field list.
 *
 * Reassigning the ticket (ticket_assigned_to actually changing) logs an
 * internal note and notifies the newly assigned technician, mirroring the
 * agent UI's reassignment flow. Moving into/out of status 4 (Resolved)
 * keeps ticket_resolved_at in sync, mirroring resolve.php.
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

$ticket_id = intval($_POST['ticket_id'] ?? 0);

$update_count = false;

if (!empty($ticket_id)) {

    // Not filtered by client_id in SQL: require_post_method.php only overrides
    // $client_id from a posted client_id, it doesn't wildcard it to "any" for an
    // ALL CLIENTS key (unlike require_get_method.php) - so `LIKE '$client_id'`
    // with client_id 0 would match nothing. Scope is checked in PHP below instead.
    $ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));

    if ($ticket_row && ($client_id == 0 || intval($ticket_row['ticket_client_id']) == $client_id)) {

        $previous_status = intval($ticket_row['ticket_status']);
        $previous_assigned_to = intval($ticket_row['ticket_assigned_to']);

        // Variable assignment from POST - assigning the current database value if a value is not provided
        require_once 'ticket_model.php';

        $resolved_sql = '';
        if ($status == 4 && $previous_status != 4) {
            $resolved_sql = ", ticket_resolved_at = NOW()";
        } elseif ($status != 4 && $previous_status == 4) {
            $resolved_sql = ", ticket_resolved_at = NULL";
        }

        $update_sql = mysqli_query($mysqli, "UPDATE tickets SET ticket_subject = '$subject', ticket_details = '$details', ticket_priority = '$priority', ticket_status = $status, ticket_billable = $billable, ticket_vendor_ticket_number = '$vendor_ticket_number', ticket_vendor_id = $vendor_id, ticket_assigned_to = $assigned_to, ticket_contact_id = $contact, ticket_asset_id = $asset, ticket_updated_at = NOW()$resolved_sql WHERE ticket_id = $ticket_id LIMIT 1");

        if ($update_sql) {
            $update_count = mysqli_affected_rows($mysqli);

            $ticket_prefix = sanitizeInput($ticket_row['ticket_prefix']);
            $ticket_number = intval($ticket_row['ticket_number']);
            $ticket_client_id = intval($ticket_row['ticket_client_id']);

            // Mirror the agent UI's reassignment note + notification, only when it actually changed
            if ($assigned_to != $previous_assigned_to) {
                $agent_name = 'Unassigned';
                if ($assigned_to != 0) {
                    $tech_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $assigned_to LIMIT 1"));
                    $agent_name = $tech_row ? sanitizeInput($tech_row['user_name']) : 'Unassigned';
                }

                mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket assigned to $agent_name via API ($api_key_name).', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");

                if ($assigned_to != 0) {
                    mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = 'Ticket $ticket_prefix$ticket_number was assigned to you', notification_action = '/agent/ticket.php?ticket_id=$ticket_id', notification_client_id = $ticket_client_id, notification_user_id = $assigned_to");
                }
            }

            logAction("Ticket", "Edit", "$ticket_prefix$ticket_number edited via API ($api_key_name)", $ticket_client_id, $ticket_id);
            logAction("API", "Success", "Edited ticket $ticket_prefix$ticket_number via API ($api_key_name)", $ticket_client_id);
        }
    }
}

// Output
require_once '../update_output.php';
