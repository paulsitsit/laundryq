<?php
// File: laundryq/customer/my_reservations.php

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

if (
    !preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $userIdString
    )
) {
    session_destroy();

    die(
        "Invalid user session. " .
        "<a href='login.php'>Please login again</a>"
    );
}

$userId = new ObjectId($userIdString);

$reservations = [];
$errorMessage = '';

try {
    $cursor = $db->reservations->find(
        [
            'user_id' => $userId
        ],
        [
            'sort' => [
                'created_at' => -1
            ]
        ]
    );

    foreach ($cursor as $reservation) {
        $reservations[] = $reservation;
    }
} catch (Throwable $e) {
    error_log(
        'Reservations loading error: ' .
        $e->getMessage()
    );

    $errorMessage = "
        <div class='alert alert-danger'>
            Could not load reservations.
            Please try again later.
        </div>
    ";
}

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

function formatDateTimeValue(
    mixed $value
): string {
    if ($value instanceof UTCDateTime) {
        return $value
            ->toDateTime()
            ->setTimezone(
                new DateTimeZone('Asia/Manila')
            )
            ->format('M j, Y g:i A');
    }

    if ($value instanceof DateTimeInterface) {
        return $value
            ->setTimezone(
                new DateTimeZone('Asia/Manila')
            )
            ->format('M j, Y g:i A');
    }

    return h($value);
}

function formatSchedule(
    mixed $date,
    mixed $time
): string {
    if (
        $date === null ||
        $date === '' ||
        $time === null ||
        $time === ''
    ) {
        return '<span class="text-muted">—</span>';
    }

    $dateText = h($date);
    $timeText = h($time);

    $timestamp = strtotime(
        (string) $date . ' ' . (string) $time
    );

    if ($timestamp === false) {
        return $dateText . '<br>' . $timeText;
    }

    return date('M j, Y', $timestamp) .
        '<br><small class="text-muted">' .
        date('g:i A', $timestamp) .
        '</small>';
}

function reservationBadge(
    string $status
): string {
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

    return
        '<span class="badge bg-' .
        $color .
        '">' .
        h($status) .
        '</span>';
}

function canModifyReservation(
    string $status
): bool {
    return in_array(
        $status,
        [
            'Pending',
            'Accepted'
        ],
        true
    );
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

    <title>My Reservations - LaundryQ</title>

    <link
        href="../assets/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="mb-2">
            <button
                type="button"
                class="btn btn-link p-0 me-2"
                onclick="history.back();"
            >
                ← Back
            </button>

            <a
                href="../index.php"
                class="text-decoration-none"
            >
                Home
            </a>
        </div>

        <div
            class="d-flex justify-content-between align-items-center mb-4"
        >
            <h3 class="text-primary mb-0">
                📋 My Reservations
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

        <?= $errorMessage ?>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table
                        class="table table-hover align-middle mb-0"
                    >
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Service</th>
                                <th>Est. Weight</th>
                                <th>Drop-Off</th>
                                <th>Pick-Up</th>
                                <th>Notes</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($reservations)): ?>
                                <tr>
                                    <td
                                        colspan="9"
                                        class="text-center text-muted py-4"
                                    >
                                        No reservations yet.
                                        <a href="reserve.php">
                                            Create one now!
                                        </a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (
                                    $reservations
                                    as $reservation
                                ): ?>
                                    <?php
                                    $reservationId = (string) (
                                        $reservation['_id'] ?? ''
                                    );

                                    $status = (string) (
                                        $reservation['status']
                                        ?? 'Pending'
                                    );

                                    $rowClass = '';

                                    if ($status === 'Pending') {
                                        $rowClass = 'table-warning';
                                    } elseif (
                                        $status === 'No-Show'
                                    ) {
                                        $rowClass = 'table-danger';
                                    }

                                    $serviceName = (string) (
                                        $reservation['service_name']
                                        ?? '—'
                                    );

                                    $serviceId =
                                        $reservation['service_id']
                                        ?? null;

                                    /*
                                    | MongoDB does not use SQL JOINs.
                                    | Load the service document separately.
                                    */
                                    if (
                                        $serviceName === '—' &&
                                        $serviceId instanceof ObjectId
                                    ) {
                                        $service = $db->services->findOne([
                                            '_id' => $serviceId
                                        ]);

                                        if ($service) {
                                            $serviceName = (string) (
                                                $service['service_name']
                                                ?? '—'
                                            );
                                        }
                                    }
                                    ?>

                                    <tr
                                        class="<?= $rowClass ?>"
                                    >
                                        <td>
                                            <a
                                                href="view_reservation.php?id=<?= urlencode(
                                                    $reservationId
                                                ) ?>"
                                                class="text-decoration-none"
                                            >
                                                #<?= h(
                                                    $reservationId
                                                ) ?>
                                            </a>
                                        </td>

                                        <td>
                                            <?= h(
                                                $reservation[
                                                    'service_type'
                                                ] ?? null
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= h($serviceName) ?>
                                        </td>

                                        <td>
                                            <?php
                                            $weight =
                                                $reservation['weight_kg']
                                                ?? null;

                                            echo $weight === null
                                                ? '—'
                                                : h($weight) . ' kg';
                                            ?>
                                        </td>

                                        <td>
                                            <?= formatSchedule(
                                                $reservation[
                                                    'reservation_date'
                                                ] ?? null,
                                                $reservation[
                                                    'reservation_time'
                                                ] ?? null
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= formatSchedule(
                                                $reservation[
                                                    'pickup_date'
                                                ] ?? null,
                                                $reservation[
                                                    'pickup_time'
                                                ] ?? null
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= h(
                                                $reservation['notes']
                                                ?? null
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= reservationBadge(
                                                $status
                                            ) ?>
                                        </td>

                                        <td>
                                            <a
                                                href="view_reservation.php?id=<?= urlencode(
                                                    $reservationId
                                                ) ?>"
                                                class="btn btn-sm btn-outline-primary mb-1"
                                            >
                                                View Details
                                            </a>

                                            <?php if (
                                                canModifyReservation(
                                                    $status
                                                )
                                            ): ?>
                                                <a
                                                    href="change_reservation_service.php?reservation_id=<?= urlencode(
                                                        $reservationId
                                                    ) ?>"
                                                    class="btn btn-sm btn-outline-info mb-1"
                                                >
                                                    Change Service
                                                </a>

                                                <form
                                                    method="POST"
                                                    action="cancel_reservation.php"
                                                    class="d-inline"
                                                    onsubmit="return confirm('Cancel this reservation?');"
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="reservation_id"
                                                        value="<?= h(
                                                            $reservationId
                                                        ) ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-danger mb-1"
                                                    >
                                                        Cancel
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <a
                href="reserve.php"
                class="btn btn-primary"
            >
                + New Reservation
            </a>

            <a
                href="place_order.php"
                class="btn btn-outline-primary"
            >
                Place an Order
            </a>
        </div>
    </div>
</body>
</html>