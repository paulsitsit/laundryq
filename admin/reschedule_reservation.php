<?php
// File: laundryq/admin/reschedule_reservation.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if (!isset($_SESSION['admin_id'])) {
    die(
        "You must be logged in as admin. " .
        "<a href='login.php'>Login here</a>"
    );
}

$reservationIdString = trim(
    (string) (
        $_POST['reservation_id'] ??
        $_GET['reservation_id'] ??
        ''
    )
);

if (
    !preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $reservationIdString
    )
) {
    $_SESSION['error'] =
        'Invalid reservation ID.';

    header('Location: reservations.php');
    exit;
}

$reservationId = new ObjectId(
    $reservationIdString
);

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    header('Location: reservations.php');
    exit;
}

$newDate = trim(
    $_POST['new_date'] ?? ''
);

$newTime = trim(
    $_POST['new_time'] ?? ''
);

$dateObject = DateTime::createFromFormat(
    '!Y-m-d',
    $newDate
);

$dateIsValid =
    $dateObject &&
    $dateObject->format('Y-m-d') === $newDate;

$timeIsValid = preg_match(
    '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
    $newTime
);

if (
    !$dateIsValid ||
    !$timeIsValid
) {
    $_SESSION['error'] =
        'Please provide a valid date and time.';

    header('Location: reservations.php');
    exit;
}

$scheduledTimestamp = strtotime(
    $newDate . ' ' . $newTime
);

if (
    $scheduledTimestamp === false ||
    $scheduledTimestamp <= time()
) {
    $_SESSION['error'] =
        'Rescheduled date and time must be in the future.';

    header('Location: reservations.php');
    exit;
}

try {
    $result = $db->reservations->updateOne(
        [
            '_id' => $reservationId
        ],
        [
            '$set' => [
                'reservation_date' => $newDate,
                'reservation_time' => $newTime,
                'status' => 'Pending',
                'notified' => false,
                'updated_by' => new ObjectId(
                    (string) $_SESSION['admin_id']
                ),
                'updated_at' =>
                    new UTCDateTime()
            ]
        ]
    );

    if ($result->getModifiedCount() === 1) {
        $_SESSION['message'] =
            "Reservation #{$reservationIdString} " .
            "has been rescheduled to {$newDate} at {$newTime}.";
    } else {
        $_SESSION['error'] =
            'Reservation was not changed. It may not exist.';
    }
} catch (Throwable $e) {
    error_log(
        'Admin reschedule error: ' .
        $e->getMessage()
    );

    $_SESSION['error'] =
        'The reservation could not be rescheduled.';
}

header('Location: reservations.php');
exit;