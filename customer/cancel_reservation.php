<?php
// File: laundryq/customer/cancel_reservation.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if (!isset($_SESSION['user_id'])) {
    die(
        "You must be logged in. " .
        "<a href='login.php'>Login here</a>"
    );
}

$userIdString = trim(
    (string) $_SESSION['user_id']
);

$reservationIdString = trim(
    (string) (
        $_POST['reservation_id'] ??
        $_GET['reservation_id'] ??
        $_GET['id'] ??
        ''
    )
);

$objectIdPattern = '/^[a-fA-F0-9]{24}$/';

if (
    !preg_match($objectIdPattern, $userIdString) ||
    !preg_match($objectIdPattern, $reservationIdString)
) {
    $_SESSION['error'] =
        'Invalid user or reservation ID.';

    header('Location: my_reservations.php');
    exit;
}

$userId = new ObjectId($userIdString);
$reservationId = new ObjectId($reservationIdString);

try {
    $result = $db->reservations->updateOne(
        [
            '_id' => $reservationId,
            'user_id' => $userId,
            'status' => [
                '$in' => [
                    'Pending',
                    'Accepted'
                ]
            ]
        ],
        [
            '$set' => [
                'status' => 'Cancelled',
                'notified' => false,
                'cancellation_reason' =>
                    'Cancelled by customer',
                'updated_at' =>
                    new UTCDateTime()
            ]
        ]
    );

    if ($result->getModifiedCount() === 1) {
        $_SESSION['message'] =
            'Reservation cancelled successfully.';
    } else {
        $_SESSION['error'] =
            'This reservation cannot be cancelled. ' .
            'It may already be completed, cancelled, or rejected.';
    }
} catch (Throwable $e) {
    error_log(
        'Cancel reservation error: ' .
        $e->getMessage()
    );

    $_SESSION['error'] =
        'The reservation could not be cancelled. ' .
        'Please try again.';
}

header('Location: my_reservations.php');
exit;