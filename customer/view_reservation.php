<?php
// File: laundryq/customer/view_reservation.php

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

$userIdString = trim((string) $_SESSION['user_id']);

if (!preg_match('/^[a-fA-F0-9]{24}$/', $userIdString)) {
    session_destroy();

    die(
        "Invalid user session. " .
        "<a href='login.php'>Please login again</a>"
    );
}

$userId = new ObjectId($userIdString);

$reservationIdString = trim((string) (
    $_GET['id'] ??
    $_POST['reservation_id'] ??
    ''
));

if (!preg_match('/^[a-fA-F0-9]{24}$/', $reservationIdString)) {
    die(
        "Invalid reservation ID. " .
        "<a href='my_reservations.php'>Back to reservations</a>"
    );
}

$reservationId = new ObjectId($reservationIdString);
$message = '';

function h(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
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

function getReservation(
    $collection,
    ObjectId $reservationId,
    ObjectId $userId,
    $servicesCollection
): ?array {
    $reservation = asArray(
        $collection->findOne([
            '_id' => $reservationId,
            'user_id' => $userId
        ])
    );

    if ($reservation === null) {
        return null;
    }

    $serviceId = $reservation['service_id'] ?? null;

    if ($serviceId instanceof ObjectId) {
        $service = asArray(
            $servicesCollection->findOne([
                '_id' => $serviceId
            ])
        );

        if ($service !== null) {
            $reservation['service_name'] =
                $service['service_name'] ?? '—';
        }
    }

    return $reservation;
}

function getStatusMessage(string $status): string
{
    $messages = [
        'Pending' =>
            'Your reservation is waiting for admin approval.',
        'Accepted' =>
            'Your reservation has been accepted. Please arrive at the shop on your scheduled drop-off date and time.',
        'Arrived' =>
            'You have arrived. Your laundry service will now be processed.',
        'Washing' =>
            'Your laundry is currently being washed.',
        'Drying' =>
            'Your laundry is currently being dried.',
        'Folding' =>
            'Your laundry is currently being folded.',
        'In Progress' =>
            'Your laundry is currently being processed.',
        'Ready for Pick-Up' =>
            'Your laundry is ready. Please collect it at your scheduled pick-up date and time.',
        'Picked Up' =>
            'Your laundry has been picked up.',
        'Completed' =>
            'Your laundry service has been completed.',
        'No-Show' =>
            'You did not arrive for your scheduled drop-off. Your reservation has been marked as No-Show.',
        'Reschedule Requested' =>
            'Your reschedule request has been sent to the admin for approval.',
        'Rescheduled' =>
            'Your reservation has been successfully rescheduled.',
        'Cancelled' =>
            'Your reservation has been cancelled.',
        'Declined' =>
            'Your reservation was rejected by the admin.',
        'Rejected' =>
            'Your reservation was rejected by the admin.'
    ];

    return $messages[$status] ??
        'Current status: ' . $status;
}

function reservationBadge(string $status): string
{
    $colors = [
        'Pending' => 'warning',
        'Accepted' => 'success',
        'Arrived' => 'info',
        'Washing' => 'primary',
        'Drying' => 'primary',
        'Folding' => 'primary',
        'In Progress' => 'primary',
        'Ready for Pick-Up' => 'primary',
        'Picked Up' => 'success',
        'Completed' => 'success',
        'No-Show' => 'danger',
        'Reschedule Requested' => 'info',
        'Rescheduled' => 'info',
        'Cancelled' => 'secondary',
        'Declined' => 'danger',
        'Rejected' => 'danger'
    ];

    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' .
        $color .
        '">' .
        h($status) .
        '</span>';
}

function formatDateValue(mixed $date): string
{
    if (!$date) {
        return '—';
    }

    $timestamp = strtotime((string) $date);

    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y', $timestamp);
}

function formatTimeValue(mixed $time): string
{
    if (!$time) {
        return '—';
    }

    $timestamp = strtotime((string) $time);

    if ($timestamp === false) {
        return h($time);
    }

    return date('g:i A', $timestamp);
}

function isValidFutureDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed !== false &&
        $parsed->format('Y-m-d') === $date &&
        $date >= date('Y-m-d');
}

function isValidTime(string $time): bool
{
    return preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        $time
    ) === 1;
}

try {
    $reservation = getReservation(
        $db->reservations,
        $reservationId,
        $userId,
        $db->services
    );

    if ($reservation === null) {
        die(
            "Reservation not found. " .
            "<a href='my_reservations.php'>Back to reservations</a>"
        );
    }
} catch (Throwable $e) {
    error_log(
        'View reservation error: ' .
        $e->getMessage()
    );

    die(
        "Reservation could not be loaded. " .
        "<a href='my_reservations.php'>Back to reservations</a>"
    );
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'request_reschedule'
) {
    $newDate = trim((string) (
        $_POST['new_date'] ?? ''
    ));

    $newTime = trim((string) (
        $_POST['new_time'] ?? ''
    ));

    if (
        !isValidFutureDate($newDate) ||
        !isValidTime($newTime)
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please select a valid future date and time.
            </div>
        ";
    } elseif (
        ($reservation['status'] ?? '') !== 'No-Show'
    ) {
        $message = "
            <div class='alert alert-danger'>
                This reservation is not eligible for rescheduling.
            </div>
        ";
    } else {
        try {
            $result = $db->reservations->updateOne(
                [
                    '_id' => $reservationId,
                    'user_id' => $userId,
                    'status' => 'No-Show'
                ],
                [
                    '$set' => [
                        'status' => 'Reschedule Requested',
                        'requested_date' => $newDate,
                        'requested_time' => $newTime,
                        'notified' => false,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            if ($result->getModifiedCount() === 1) {
                $message = "
                    <div class='alert alert-success'>
                        Reschedule request sent to admin for approval.
                    </div>
                ";

                $reservation = getReservation(
                    $db->reservations,
                    $reservationId,
                    $userId,
                    $db->services
                );
            } else {
                $message = "
                    <div class='alert alert-danger'>
                        The reschedule request could not be submitted.
                    </div>
                ";
            }
        } catch (Throwable $e) {
            error_log(
                'Reschedule request error: ' .
                $e->getMessage()
            );

            $message = "
                <div class='alert alert-danger'>
                    A database error occurred. Please try again.
                </div>
            ";
        }
    }
}

$status = (string) (
    $reservation['status'] ?? 'Pending'
);

$statusClass = 'info';

if ($status === 'Pending') {
    $statusClass = 'warning';
} elseif (in_array(
    $status,
    [
        'Completed',
        'Picked Up'
    ],
    true
)) {
    $statusClass = 'success';
} elseif (in_array(
    $status,
    [
        'No-Show',
        'Cancelled',
        'Declined',
        'Rejected'
    ],
    true
)) {
    $statusClass = 'danger';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Reservation Details - LaundryQ</title>
    <link
        href="../assets/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        .detail-card {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: .5rem;
            margin-bottom: 1rem;
        }

        .schedule-card {
            padding: 1.5rem;
            border-radius: .5rem;
            margin-bottom: 1rem;
        }

        .schedule-card.dropoff {
            background: #e7f1ff;
            border: 1px solid #b6d4fe;
        }

        .schedule-card.pickup {
            background: #fff4e5;
            border: 1px solid #ffe1b3;
        }

        .status-message {
            padding: 1rem;
            border-radius: .5rem;
            margin-bottom: 1.5rem;
        }

        .status-message.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .status-message.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .status-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-message.danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="mb-3">
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm me-2"
                onclick="goBack()"
            >
                ← Back
            </button>

            <a
                href="../index.php"
                class="btn btn-outline-primary btn-sm"
            >
                Home
            </a>
        </div>

        <div
            class="d-flex justify-content-between align-items-center mb-4"
        >
            <h3 class="text-primary mb-0">
                📋 Reservation #<?= h($reservationIdString) ?>
            </h3>

            <a
                href="logout.php"
                class="btn btn-outline-secondary btn-sm"
            >
                Logout
            </a>
        </div>

        <p class="text-muted">
            Logged in as:
            <strong>
                <?= h($_SESSION['full_name'] ?? '') ?>
            </strong>
        </p>

        <?php if ($message !== ''): ?>
            <?= $message ?>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div
                    class="d-flex justify-content-between align-items-center mb-3"
                >
                    <h5 class="mb-0">Current Status</h5>
                    <?= reservationBadge($status) ?>
                </div>

                <div class="status-message <?= h($statusClass) ?>">
                    <strong>
                        <?= h(getStatusMessage($status)) ?>
                    </strong>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Schedule</h5>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="schedule-card dropoff">
                            <label
                                class="fw-bold text-primary small text-uppercase"
                            >
                                Drop-Off
                            </label>

                            <p class="mb-0 fs-5">
                                <?= formatDateValue(
                                    $reservation[
                                        'reservation_date'
                                    ] ?? null
                                ) ?>
                            </p>

                            <p class="mb-0 text-muted">
                                <?= formatTimeValue(
                                    $reservation[
                                        'reservation_time'
                                    ] ?? null
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="schedule-card pickup">
                            <label
                                class="fw-bold text-warning small text-uppercase"
                            >
                                Pick-Up
                            </label>

                            <p class="mb-0 fs-5">
                                <?= formatDateValue(
                                    $reservation[
                                        'pickup_date'
                                    ] ?? null
                                ) ?>
                            </p>

                            <p class="mb-0 text-muted">
                                <?= formatTimeValue(
                                    $reservation[
                                        'pickup_time'
                                    ] ?? null
                                ) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Reservation Details</h5>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="detail-card">
                            <label class="fw-bold text-muted small">
                                Service Type
                            </label>
                            <p class="mb-0">
                                <?= h(
                                    $reservation[
                                        'service_type'
                                    ] ?? null
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="detail-card">
                            <label class="fw-bold text-muted small">
                                Service
                            </label>
                            <p class="mb-0">
                                <?= h(
                                    $reservation[
                                        'service_name'
                                    ] ?? null
                                ) ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="detail-card">
                            <label class="fw-bold text-muted small">
                                Estimated Weight
                            </label>
                            <p class="mb-0">
                                <?php
                                $weight = $reservation[
                                    'weight_kg'
                                ] ?? null;

                                echo $weight === null
                                    ? '—'
                                    : h($weight) . ' kg';
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="detail-card">
                            <label class="fw-bold text-muted small">
                                Notes
                            </label>
                            <p class="mb-0">
                                <?= nl2br(
                                    h(
                                        $reservation['notes']
                                        ?? 'None'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (
            $status === 'Rescheduled' &&
            !empty($reservation['requested_date'] ?? null) &&
            !empty($reservation['requested_time'] ?? null)
        ): ?>
            <div class="card shadow-sm mb-4 border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">New Scheduled Drop-Off</h5>
                </div>

                <div class="card-body">
                    <p class="mb-1">
                        <strong>Date:</strong>
                        <?= formatDateValue(
                            $reservation['requested_date']
                        ) ?>
                    </p>

                    <p class="mb-0">
                        <strong>Time:</strong>
                        <?= formatTimeValue(
                            $reservation['requested_time']
                        ) ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status === 'No-Show'): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Request Reschedule</h5>
                </div>

                <div class="card-body">
                    <form
                        method="POST"
                        action="view_reservation.php?id=<?= urlencode(
                            $reservationIdString
                        ) ?>"
                    >
                        <input
                            type="hidden"
                            name="reservation_id"
                            value="<?= h($reservationIdString) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="request_reschedule"
                        >

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label
                                    for="newDate"
                                    class="form-label"
                                >
                                    New Date
                                </label>

                                <input
                                    type="date"
                                    name="new_date"
                                    id="newDate"
                                    class="form-control"
                                    min="<?= date('Y-m-d') ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6 mb-3">
                                <label
                                    for="newTime"
                                    class="form-label"
                                >
                                    New Time
                                </label>

                                <input
                                    type="time"
                                    name="new_time"
                                    id="newTime"
                                    class="form-control"
                                    required
                                >
                            </div>
                        </div>

                        <p class="text-muted small">
                            Your admin will review and approve the new schedule.
                        </p>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Submit Reschedule Request
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a
                href="my_reservations.php"
                class="btn btn-outline-primary me-2"
            >
                ← Back to Reservations
            </a>

            <a
                href="reserve.php"
                class="btn btn-primary"
            >
                + New Reservation
            </a>
        </div>
    </div>

    <script>
    function goBack() {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        window.location.href = '../index.php';
    }
    </script>
</body>
</html>
