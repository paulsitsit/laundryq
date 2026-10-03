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

function asArray(mixed $document): ?array
{
    if ($document === null) {
        return null;
    }

    if (is_array($document)) {
        return $document;
    }

    if (method_exists($document, 'getArrayCopy')) {
        return $document->getArrayCopy();
    }

    return null;
}

function updateReservationStatus(
    $collection,
    ObjectId $reservationId,
    ObjectId $adminId,
    array $currentStatuses,
    string $newStatus
) {
    return $collection->updateOne(
        [
            '_id' => $reservationId,
            'status' => count($currentStatuses) === 1
                ? $currentStatuses[0]
                : [
                    '$in' => $currentStatuses
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

$reservationIdString = trim((string) (
    $_POST['reservation_id'] ?? ''
));

$action = trim((string) (
    $_POST['action'] ?? ''
));

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

$result = null;
$emailStatus = null;
$successMessage = '';

try {
    $reservation = asArray(
        $db->reservations->findOne([
            '_id' => $reservationId
        ])
    );

    if ($reservation === null) {
        setError('Reservation not found.');
        redirectToReservations();
    }

    /*
     * Forward-only workflow:
     *
     * Pending
     * → Accepted
     * → Arrived
     * → Washing
     * → Drying
     * → Folding
     * → Ready for Pick-Up
     * → Picked Up
     * → Completed
     */
    switch ($action) {
        case 'accept_pending':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Pending'],
                'Accepted'
            );

            $emailStatus = 'Accepted';

            $successMessage =
                "Reservation #{$reservationIdString} accepted.";
            break;

        case 'reject_pending':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Pending'],
                'Rejected'
            );

            $emailStatus = 'Rejected';

            $successMessage =
                "Reservation #{$reservationIdString} rejected.";
            break;

        case 'mark_arrived':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                [
                    'Accepted',
                    'Rescheduled'
                ],
                'Arrived'
            );

            $emailStatus = 'Arrived';

            $successMessage =
                "Reservation #{$reservationIdString} marked as Arrived.";
            break;

        case 'mark_no_show':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                [
                    'Accepted',
                    'Rescheduled'
                ],
                'No-Show'
            );

            $emailStatus = 'No-Show';

            $successMessage =
                "Reservation #{$reservationIdString} marked as No-Show.";
            break;

        case 'start_washing':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Arrived'],
                'Washing'
            );

            $emailStatus = 'Washing';

            $successMessage =
                "Reservation #{$reservationIdString} is now Washing.";
            break;

        case 'start_drying':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Washing'],
                'Drying'
            );

            $emailStatus = 'Drying';

            $successMessage =
                "Reservation #{$reservationIdString} is now Drying.";
            break;

        case 'start_folding':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Drying'],
                'Folding'
            );

            $emailStatus = 'Folding';

            $successMessage =
                "Reservation #{$reservationIdString} is now Folding.";
            break;

        case 'mark_ready_pickup':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Folding'],
                'Ready for Pick-Up'
            );

            $emailStatus = 'Ready for Pick-Up';

            $successMessage =
                "Reservation #{$reservationIdString} " .
                "marked as Ready for Pick-Up.";
            break;

        case 'mark_picked_up':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Ready for Pick-Up'],
                'Picked Up'
            );

            $emailStatus = 'Picked Up';

            $successMessage =
                "Reservation #{$reservationIdString} " .
                "marked as Picked Up.";
            break;

        case 'complete':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Picked Up'],
                'Completed'
            );

            $emailStatus = 'Completed';

            $successMessage =
                "Reservation #{$reservationIdString} " .
                "marked as Completed.";
            break;

        case 'cancel':
            /*
             * Cancellation is allowed only before the item is ready
             * for pick-up. It cannot cancel a Picked Up or Completed
             * reservation.
             */
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                [
                    'Pending',
                    'Accepted',
                    'Rescheduled',
                    'Arrived',
                    'Washing',
                    'Drying',
                    'Folding'
                ],
                'Cancelled'
            );

            $emailStatus = 'Cancelled';

            $successMessage =
                "Reservation #{$reservationIdString} cancelled.";
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
                        'weight_kg' =>
                            $reservation['requested_weight_kg'] ??
                            $reservation['weight_kg'] ??
                            0,
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

            $successMessage =
                "Service change approved for reservation #" .
                $reservationIdString . '.';
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

            $successMessage =
                "Service change declined for reservation #" .
                $reservationIdString . '.';
            break;

        /*
         * Reschedule actions are retained here in case you later
         * restore them to the admin interface. They will not appear
         * in the updated reservation dropdown.
         */
        case 'accept_reschedule':
            $requestedDate =
                $reservation['requested_date'] ?? null;

            $requestedTime =
                $reservation['requested_time'] ?? null;

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

            $successMessage =
                "Reschedule accepted for reservation #" .
                $reservationIdString . '.';
            break;

        case 'reject_reschedule':
            $result = updateReservationStatus(
                $db->reservations,
                $reservationId,
                $adminId,
                ['Reschedule Requested'],
                'No-Show'
            );

            $emailStatus = 'No-Show';

            $successMessage =
                "Reschedule rejected for reservation #" .
                $reservationIdString . '.';
            break;

        /*
         * This action was intentionally removed.
         *
         * The old generic update_status handler allowed an admin
         * to jump backwards or skip the workflow, for example:
         * Washing → Arrived or Pending → Completed.
         */
        case 'update_status':
            setError(
                'Direct status updates are disabled. ' .
                'Use the next available workflow action.'
            );
            redirectToReservations();

        case 'start_processing':
            setError(
                'Use Start Washing instead of Start. ' .
                'The laundry process is now forward-only.'
            );
            redirectToReservations();

        default:
            setError('Unknown reservation action.');
            redirectToReservations();
    }

    if (!$result || $result->getMatchedCount() === 0) {
        setError(
            'The reservation could not be updated. ' .
            'Its current status does not allow that action.'
        );

        redirectToReservations();
    }

    $updatedReservation = asArray(
        $db->reservations->findOne([
            '_id' => $reservationId
        ])
    );

    $emailSent = false;

    if (
        $updatedReservation !== null &&
        ($updatedReservation['user_id'] ?? null)
            instanceof ObjectId &&
        $emailStatus !== null
    ) {
        $customer = asArray(
            $db->users->findOne([
                '_id' => $updatedReservation['user_id']
            ])
        );

        $customerEmail = trim((string) (
            $customer['email'] ??
            $customer['email_address'] ??
            ''
        ));

        $customerName = trim((string) (
            $customer['full_name'] ??
            $customer['name'] ??
            'LaundryQ Customer'
        ));

        if (
            $customerEmail !== '' &&
            function_exists('sendReservationStatusEmail')
        ) {
            $emailSent = sendReservationStatusEmail(
                $customerEmail,
                $customerName,
                $reservationIdString,
                $emailStatus
            );
        }
    }

    $db->reservations->updateOne(
        [
            '_id' => $reservationId
        ],
        [
            '$set' => [
                'notified' => $emailSent,
                'notification_updated_at' =>
                    new UTCDateTime()
            ]
        ]
    );

    setMessage($successMessage);

    if (!$emailSent) {
        setError(
            'Reservation updated, but the customer email notification ' .
            'could not be sent.'
        );
    }
} catch (Throwable $e) {
    error_log(
        'Reservation action error: ' .
        $e->getMessage()
    );

    setError(
        'The reservation action could not be completed. ' .
        'Check the PHP error log.'
    );
}

redirectToReservations();