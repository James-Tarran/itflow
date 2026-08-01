<?php

/*
 * API - tickets/reply.php
 * Adds a reply/note to a ticket. Mirrors the agent UI's ticket reply flow
 * (agent/post/ticket.php): a Public reply bumps ticket_updated_at and can
 * optionally queue a notification email to the ticket's contact, exactly
 * like picking "Public + Email" in the UI.
 *
 * POST Parameters:
 *   api_key (required)
 *   ticket_id (required)
 *   reply (required) - the reply/note text
 *   reply_type (optional) - "Public" (default) or "Internal"
 *   notify (optional) - 1 (default) to also email the contact on a Public reply, 0 to skip
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

require_once "../../../includes/load_global_settings.php";

$ticket_id = intval($_POST['ticket_id'] ?? 0);
$reply = isset($_POST['reply']) ? mysqli_real_escape_string($mysqli, $_POST['reply']) : '';
$reply_type = (isset($_POST['reply_type']) && strtolower($_POST['reply_type']) === 'internal') ? 'Internal' : 'Public';
$notify = isset($_POST['notify']) ? intval($_POST['notify']) : 1;

$update_count = false;

if (!empty($ticket_id) && $reply !== '') {

    $ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tickets.*, ticket_statuses.ticket_status_name, contacts.contact_name, contacts.contact_email
        FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        WHERE ticket_id = $ticket_id LIMIT 1"));

    $ticket_client_id = intval($ticket_row['ticket_client_id'] ?? 0);

    // Scope check: an "ALL CLIENTS" key (client_id 0) may reply to any ticket,
    // a client-restricted key only to its own client's tickets.
    if ($ticket_row && ($client_id == 0 || $ticket_client_id == $client_id)) {

        $ticket_prefix = sanitizeInput($ticket_row['ticket_prefix']);
        $ticket_number = intval($ticket_row['ticket_number']);
        $ticket_subject = sanitizeInput($ticket_row['ticket_subject']);
        $ticket_status_name = sanitizeInput($ticket_row['ticket_status_name']);
        $ticket_first_response_at = sanitizeInput($ticket_row['ticket_first_response_at']);
        $contact_name = sanitizeInput($ticket_row['contact_name']);
        $contact_email = sanitizeInput($ticket_row['contact_email']);
        $url_key = sanitizeInput($ticket_row['ticket_url_key']);

        $insert_sql = mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$reply', ticket_reply_type = '$reply_type', ticket_reply_time_worked = '00:00:00', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");

        if ($insert_sql) {
            $update_count = 1;

            mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $ticket_id");

            if (empty($ticket_first_response_at) && $reply_type == 'Public') {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticket_id");
            }

            logAction("Ticket", "Reply", "$ticket_prefix$ticket_number replied to via API ($api_key_name) and was a $reply_type reply", $ticket_client_id, $ticket_id);
            logAction("API", "Success", "Replied to ticket $ticket_prefix$ticket_number via API ($api_key_name)", $ticket_client_id);

            // Email the contact, mirroring agent/post/ticket.php's "Public + Email" flow
            if ($reply_type == 'Public' && $notify == 1 && !empty($config_smtp_provider) && filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                $config_base_url_esc = sanitizeInput($config_base_url);
                $config_ticket_from_email_esc = sanitizeInput($config_ticket_from_email);
                $config_ticket_from_name_esc = sanitizeInput($config_ticket_from_name);

                $company_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_name FROM companies WHERE company_id = 1"));
                $company_name = sanitizeInput($company_row['company_name']);

                $subject = "Ticket update - [$ticket_prefix$ticket_number] - $ticket_subject";
                $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been updated.<br><br>--------------------------------<br>$reply<br>--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status_name<br>Portal: <a href=\'https://$config_base_url_esc/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email_esc";

                $data = [[
                    'from' => $config_ticket_from_email_esc,
                    'from_name' => $config_ticket_from_name_esc,
                    'recipient' => $contact_email,
                    'recipient_name' => $contact_name,
                    'subject' => $subject,
                    'body' => $body
                ]];

                addToMailQueue($data);
            }

            customAction($reply_type == 'Internal' ? 'ticket_reply_agent_internal' : 'reply_reply_agent_public', $ticket_id);
        }
    }
}

// Output
require_once '../update_output.php';
