<?php
// File: laundryq/customer/track_order.php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if (!isset($_SESSION['user_id'])) {
    die(
        "You must be logged in to view orders. " .
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
$orders = [];
$reservations = [];
$errorMessage = '';

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

function formatMongoDate(mixed $value): string
{
    if ($value instanceof UTCDateTime) {
        return $value
            ->toDateTime()
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('M j, Y g:i A');
    }

    return h($value);
}

function statusBadge(string $status): string
{
    $colors = [
        'Pending' => 'secondary',
        'Accepted' => 'success',
        'Washing' => 'info',
        'Drying' => 'info',
        'Folding' => 'warning',
        'In Progress' => 'primary',
        'Ready for Pickup' => 'primary',
        'Ready for Pick-Up' => 'primary',
        'Picked Up' => 'success',
        'Completed' => 'success',
        'Cancelled' => 'danger',
        'Cancellation Requested' => 'warning',
        'Service Change Requested' => 'info',
        'Declined' => 'danger',
        'Rejected' => 'danger',
        'No-Show' => 'danger',
        'Rescheduled' => 'info'
    ];

    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' .
        $color .
        '">' .
        h($status) .
        '</span>';
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
        'Declined' => 'danger',
        'Rejected' => 'danger',
        'No-Show' => 'danger',
        'Reschedule Requested' => 'info',
        'Rescheduled' => 'info',
        'Cancelled' => 'secondary',
        'Service Change Requested' => 'info'
    ];

    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' .
        $color .
        '">' .
        h($status) .
        '</span>';
}

function findServiceName(
    mixed $serviceId,
    $servicesCollection
): string {
    if (!$serviceId instanceof ObjectId) {
        return '—';
    }

    $service = $servicesCollection->findOne([
        '_id' => $serviceId
    ]);

    if ($service === null) {
        return '—';
    }

    return (string) ($service['service_name'] ?? '—');
}

try {
    $orderCursor = $db->orders->find(
        ['user_id' => $userId],
        ['sort' => ['created_at' => -1]]
    );

    foreach ($orderCursor as $order) {
        $order['display_service_name'] = findServiceName(
            $order['service_id'] ?? null,
            $db->services
        );

        $orders[] = $order;
    }

    $reservationCursor = $db->reservations->find(
        ['user_id' => $userId],
        ['sort' => ['created_at' => -1]]
    );

    foreach ($reservationCursor as $reservation) {
        $reservation['display_service_name'] = findServiceName(
            $reservation['service_id'] ?? null,
            $db->services
        );

        $reservations[] = $reservation;
    }
} catch (Throwable $e) {
    error_log(
        'Track order error: ' . $e->getMessage()
    );

    $errorMessage = '
        <div class="alert alert-danger">
            Orders and reservations could not be loaded.
            Please try again later.
        </div>
    ';
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
    <title>My Orders - LaundryQ</title>
    <link
        href="../assets/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="d-flex align-items-center gap-2 mb-3">
            <a
                href="../index.php"
                class="btn btn-outline-primary btn-sm"
            >
                Home
            </a>

            <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                onclick="goBack()"
            >
                ← Back
            </button>
        </div>

        <div
            class="d-flex justify-content-between align-items-center mb-4"
        >
            <h3 class="text-primary mb-0">
                📦 My Orders
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

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="mb-3">Orders</h5>

                <div class="table-responsive">
                    <table
                        class="table table-hover align-middle mb-0"
                    >
                        <thead class="table-light">
                        <tr>
                            <th>Order ID</th>
                            <th>Service</th>
                            <th>Weight</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date Ordered</th>
                            <th>Action</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td
                                    colspan="7"
                                    class="text-center text-muted py-3"
                                >
                                    No orders yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $orderId = (string) (
                                    $order['_id'] ?? ''
                                );

                                $orderStatus = (string) (
                                    $order['status'] ?? 'Pending'
                                );

                                $orderService =
                                    $order['display_service_name'] ?? '—';

                                $orderWeight =
                                    $order['weight_kg'] ?? null;

                                $orderTotal = (float) (
                                    $order['total_amount'] ?? 0
                                );

                                $orderDate =
                                    $order['created_at'] ?? null;
                                ?>

                                <tr>
                                    <td>#<?= h($orderId) ?></td>
                                    <td><?= h($orderService) ?></td>
                                    <td>
                                        <?= $orderWeight === null
                                            ? '—'
                                            : h($orderWeight) . ' kg' ?>
                                    </td>
                                    <td>
                                        ₱<?= number_format(
                                            $orderTotal,
                                            2
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= statusBadge($orderStatus) ?>
                                    </td>
                                    <td>
                                        <?= formatMongoDate($orderDate) ?>
                                    </td>
                                    <td>
                                        <?php if (
                                            $orderStatus === 'Pending'
                                        ): ?>
                                            <div class="d-flex gap-2">
                                                <a
                                                    href="change_order_service.php?order_id=<?= urlencode($orderId) ?>"
                                                    class="btn btn-outline-primary btn-sm"
                                                >
                                                    Change Service
                                                </a>

                                                <form
                                                    method="POST"
                                                    action="cancel_order.php"
                                                    onsubmit="return confirm('Request cancellation for this order?');"
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="order_id"
                                                        value="<?= h($orderId) ?>"
                                                    >
                                                    <button
                                                        type="submit"
                                                        class="btn btn-outline-danger btn-sm"
                                                    >
                                                        Cancel
                                                    </button>
                                                </form>
                                            </div>
                                        <?php elseif (
                                            $orderStatus ===
                                            'Cancellation Requested'
                                        ): ?>
                                            <span class="text-muted small">
                                                Waiting for admin approval
                                            </span>
                                        <?php elseif (
                                            $orderStatus ===
                                            'Service Change Requested'
                                        ): ?>
                                            <span class="text-muted small">
                                                Change request waiting for admin approval
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
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

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">📅 My Reservations</h5>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table
                        class="table table-hover align-middle mb-0"
                    >
                        <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Service</th>
                            <th>Weight</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($reservations)): ?>
                            <tr>
                                <td
                                    colspan="8"
                                    class="text-center text-muted py-3"
                                >
                                    No reservations yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reservations as $reservation): ?>
                                <?php
                                $reservationId = (string) (
                                    $reservation['_id'] ?? ''
                                );

                                $reservationStatus = (string) (
                                    $reservation['status'] ?? 'Pending'
                                );

                                $rowClass = in_array(
                                    $reservationStatus,
                                    [
                                        'Declined',
                                        'Rejected',
                                        'No-Show'
                                    ],
                                    true
                                ) ? 'table-danger' : '';

                                $reservationService =
                                    $reservation[
                                        'display_service_name'
                                    ] ?? '—';
                                ?>

                                <tr class="<?= h($rowClass) ?>">
                                    <td>
                                        <?= h(
                                            $reservation[
                                                'service_type'
                                            ] ?? null
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= h($reservationService) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $weight = $reservation[
                                            'weight_kg'
                                        ] ?? null;

                                        echo $weight === null
                                            ? '—'
                                            : h($weight) . ' kg';
                                        ?>
                                    </td>
                                    <td>
                                        <?= h(
                                            $reservation[
                                                'reservation_date'
                                            ] ?? null
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= h(
                                            $reservation[
                                                'reservation_time'
                                            ] ?? null
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= h(
                                            $reservation['notes'] ?? null
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= reservationBadge(
                                            $reservationStatus
                                        ) ?>
                                    </td>
                                    <td>
                                        <a
                                            href="view_reservation.php?id=<?= urlencode($reservationId) ?>"
                                            class="btn btn-outline-primary btn-sm mb-1"
                                        >
                                            View Details
                                        </a>

                                        <?php if (
                                            in_array(
                                                $reservationStatus,
                                                [
                                                    'Pending',
                                                    'Accepted'
                                                ],
                                                true
                                            )
                                        ): ?>
                                            <a
                                                href="change_reservation_service.php?reservation_id=<?= urlencode($reservationId) ?>"
                                                class="btn btn-outline-primary btn-sm mb-1"
                                            >
                                                Change Service
                                            </a>
                                        <?php elseif (
                                            $reservationStatus ===
                                            'Service Change Requested'
                                        ): ?>
                                            <span class="text-muted small">
                                                Waiting for approval
                                            </span>
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
                href="place_order.php"
                class="btn btn-primary me-2"
            >
                + Place Another Order
            </a>

            <a
                href="reserve.php"
                class="btn btn-outline-primary"
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

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
