<?php
// File: laundryq/admin/handle_reservation_action.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/email.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

function redirectToReservations(): never
{
    header('Location: reservations.php');
    exit;
}

function setMessage(string $message): void
{
    $_SESSION['message'] = $message;
}

function setError(string $message): void
{
    $_SESSION['error'] = $message;
}

if (!isset($_SESSION['admin_id'])) {
    die(
        "You must be logged in as admin. " .
        "<a href='login.php'>Login here</a>"
    );
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['reservation_id']) ||
    !isset($_POST['action'])
) {
    redirectToReservations();
}

$adminIdString = trim((string) $_SESSION['admin_id']);
$reservationIdString = trim((string) $_POST['reservation_id']);
$action = trim((string) $_POST['action']);

$idPattern = '/^[a-fA-F0-9]{24}$/';

if (
    !preg_match($idPattern, $adminIdString) ||
    !preg_match($idPattern, $reservationIdString)
) {
    setError('Invalid admin or reservation ID.');
    redirectToReservations();
}

$adminId = new ObjectId($adminIdString);
$reservationId = new ObjectId($reservationIdString);
$emailStatus = null;
$result = null;

try {
    $reservation = $db->reservations->findOne([
        '_id' => $reservationId
    ]);

    if (!$reservation) {
        setError('Reservation not found.');
        redirectToReservations();
    }

    switch ($action) {
        case 'accept_pending':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Pending'
                ],
                [
                    '$set' => [
                        'status' => 'Accepted',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Accepted';
            setMessage(
                "Reservation #{$reservationIdString} accepted."
            );
            break;

        case 'reject_pending':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Pending'
                ],
                [
                    '$set' => [
                        'status' => 'Rejected',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Rejected';
            setMessage(
                "Reservation #{$reservationIdString} rejected."
            );
            break;

        case 'update_status':
            $newStatus = trim((string) ($_POST['status'] ?? ''));

            $allowedStatuses = [
                'Washing',
                'Drying',
                'Folding',
                'Ready for Pick-Up',
                'Completed'
            ];

            if (!in_array($newStatus, $allowedStatuses, true)) {
                setError('Invalid reservation status.');
                redirectToReservations();
            }

            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => [
                        '$nin' => [
                            'Completed',
                            'Cancelled',
                            'Rejected'
                        ]
                    ]
                ],
                [
                    '$set' => [
                        'status' => $newStatus,
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = $newStatus;
            setMessage(
                "Reservation #{$reservationIdString} " .
                "updated to {$newStatus}."
            );
            break;

        case 'approve_service_change':
            $requestedServiceId =
                $reservation['requested_service_id'] ?? null;

            if (!$requestedServiceId instanceof ObjectId) {
                setError('No requested service was found.');
                redirectToReservations();
            }

            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Service Change Requested'
                ],
                [
                    '$set' => [
                        'service_id' => $requestedServiceId,
                        'weight_kg' => $reservation['requested_weight_kg'] ?? $reservation['weight_kg'] ?? 0,
                        'status' => 'Pending',
                        'service_change_result' => 'approved',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ],
                    '$unset' => [
                        'requested_service_id' => '',
                        'requested_weight_kg' => ''
                    ]
                ]
            );

            $emailStatus = 'Service change approved';
            setMessage(
                "Service change approved for reservation " .
                "#{$reservationIdString}."
            );
            break;

        case 'decline_service_change':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Service Change Requested'
                ],
                [
                    '$set' => [
                        'status' => 'Pending',
                        'service_change_result' => 'declined',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ],
                    '$unset' => [
                        'requested_service_id' => '',
                        'requested_weight_kg' => ''
                    ]
                ]
            );

            $emailStatus = 'Service change declined';
            setMessage(
                "Service change declined for reservation " .
                "#{$reservationIdString}."
            );
            break;

        case 'mark_arrived':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => [
                        '$in' => ['Accepted', 'Rescheduled']
                    ]
                ],
                [
                    '$set' => [
                        'status' => 'Arrived',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Arrived';
            setMessage(
                "Reservation #{$reservationIdString} marked as Arrived."
            );
            break;

        case 'mark_no_show':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => [
                        '$in' => ['Accepted', 'Rescheduled']
                    ]
                ],
                [
                    '$set' => [
                        'status' => 'No-Show',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'No-Show';
            setMessage(
                "Reservation #{$reservationIdString} marked as No-Show."
            );
            break;

        case 'start_processing':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Arrived'
                ],
                [
                    '$set' => [
                        'status' => 'In Progress',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'In Progress';
            setMessage(
                "Reservation #{$reservationIdString} is now In Progress."
            );
            break;

        case 'mark_ready_pickup':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => [
                        '$in' => [
                            'In Progress',
                            'Washing',
                            'Drying',
                            'Folding'
                        ]
                    ]
                ],
                [
                    '$set' => [
                        'status' => 'Ready for Pick-Up',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Ready for Pick-Up';
            setMessage(
                "Reservation #{$reservationIdString} marked as Ready for Pick-Up."
            );
            break;

        case 'mark_picked_up':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Ready for Pick-Up'
                ],
                [
                    '$set' => [
                        'status' => 'Picked Up',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Picked Up';
            setMessage(
                "Reservation #{$reservationIdString} marked as Picked Up."
            );
            break;

        case 'complete':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Picked Up'
                ],
                [
                    '$set' => [
                        'status' => 'Completed',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Completed';
            setMessage(
                "Reservation #{$reservationIdString} marked as Completed."
            );
            break;

        case 'cancel':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => [
                        '$nin' => [
                            'Completed',
                            'Picked Up',
                            'Cancelled',
                            'Rejected'
                        ]
                    ]
                ],
                [
                    '$set' => [
                        'status' => 'Cancelled',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Cancelled';
            setMessage(
                "Reservation #{$reservationIdString} cancelled."
            );
            break;

        case 'accept_reschedule':
            $requestedDate = $reservation['requested_date'] ?? null;
            $requestedTime = $reservation['requested_time'] ?? null;

            if (!$requestedDate || !$requestedTime) {
                setError('No reschedule date or time was found.');
                redirectToReservations();
            }

            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Reschedule Requested'
                ],
                [
                    '$set' => [
                        'status' => 'Rescheduled',
                        'reservation_date' => $requestedDate,
                        'reservation_time' => $requestedTime,
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'Rescheduled';
            setMessage(
                "Reschedule accepted for reservation #{$reservationIdString}."
            );
            break;

        case 'reject_reschedule':
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'status' => 'Reschedule Requested'
                ],
                [
                    '$set' => [
                        'status' => 'No-Show',
                        'notified' => false,
                        'updated_by' => $adminId,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $emailStatus = 'No-Show';
            setMessage(
                "Reschedule rejected for reservation #{$reservationIdString}."
            );
            break;

        default:
            setError('Unknown reservation action.');
            redirectToReservations();
    }

    if (!$result || $result->getMatchedCount() === 0) {
        setError(
            'The reservation could not be updated. ' .
            'Its current status may not allow this action.'
        );
        redirectToReservations();
    }

    $updatedReservation = $db->reservations->findOne([
        '_id' => $reservationId
    ]);

    if (
        $updatedReservation &&
        ($updatedReservation['user_id'] ?? null) instanceof ObjectId &&
        $emailStatus !== null
    ) {
        $customer = $db->users->findOne([
            '_id' => $updatedReservation['user_id']
        ]);

        if (!$customer) {
            setError(
                'Reservation updated, but the customer could not be found.'
            );
        } elseif (
            empty($customer['email']) ||
            !filter_var(
                (string) $customer['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {
            setError(
                'Reservation updated, but the customer email is missing or invalid.'
            );
        } else {
            $emailSent = sendReservationStatusEmail(
                (string) $customer['email'],
                (string) ($customer['full_name'] ?? 'Customer'),
                $reservationIdString,
                $emailStatus
            );

            if (!$emailSent) {
                setError(
                    'Reservation updated, but the email notification could not be sent.'
                );
            }
        }
    }
} catch (Throwable $e) {
    error_log('Reservation action error: ' . $e->getMessage());
    setError(
        'The reservation action could not be completed. Check the PHP error log.'
    );
}

redirectToReservations();
