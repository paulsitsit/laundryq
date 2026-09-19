<?php
// File: laundryq/admin/dashboard.php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if (!isset($_SESSION['admin_id'])) {
    die("You must be logged in as admin. <a href='login.php'>Login here</a>");
}

$adminIdString = trim((string) $_SESSION['admin_id']);

if (!preg_match('/^[a-fA-F0-9]{24}$/', $adminIdString)) {
    session_destroy();
    die("Invalid admin session. <a href='login.php'>Please login again</a>");
}

function h(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function validObjectId(string $value): bool
{
    return preg_match('/^[a-fA-F0-9]{24}$/', $value) === 1;
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

function findById($collection, mixed $id): ?array
{
    if (!$id instanceof ObjectId) {
        return null;
    }

    return asArray($collection->findOne(['_id' => $id]));
}

function formatDateValue(mixed $value): string
{
    if ($value instanceof UTCDateTime) {
        return $value
            ->toDateTime()
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('M j, Y g:i A');
    }

    if ($value instanceof DateTimeInterface) {
        return $value
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
        'Rejected' => 'danger'
    ];

    $color = $colors[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . h($status) . '</span>';
}

$message = '';
$orders = [];
$weeklyCustomers = array_fill(0, 7, 0);
$weeklyLabels = [];

foreach (['message' => 'success', 'error' => 'danger'] as $key => $type) {
    if (isset($_SESSION[$key])) {
        $message .= '<div class="alert alert-' . $type .
            ' alert-dismissible fade show">' .
            h($_SESSION[$key]) .
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' .
            '</div>';
        unset($_SESSION[$key]);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
        $orderIdText = trim((string) $_POST['order_id']);
        $action = trim((string) ($_POST['action'] ?? 'update_status'));

        if (!validObjectId($orderIdText)) {
            throw new RuntimeException('Invalid order ID.');
        }

        $orderId = new ObjectId($orderIdText);
        $order = asArray($db->orders->findOne(['_id' => $orderId]));

        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $set = [
            'notified' => false,
            'updated_at' => new UTCDateTime()
        ];
        $unset = [];

        if ($action === 'approve_service_change') {
            $requestedServiceId = $order['requested_service_id'] ?? null;

            if (!$requestedServiceId instanceof ObjectId) {
                throw new RuntimeException('No requested service was found.');
            }

            $set['service_id'] = $requestedServiceId;
            $set['weight_kg'] = $order['requested_weight_kg'] ?? $order['weight_kg'] ?? 0;
            $set['total_amount'] = $order['requested_total_amount'] ?? $order['total_amount'] ?? 0;
            $set['status'] = 'Pending';
            $set['service_change_result'] = 'approved';

            $unset = [
                'requested_service_id' => '',
                'requested_weight_kg' => '',
                'requested_total_amount' => ''
            ];

            $_SESSION['message'] = "Service change approved for order #{$orderIdText}.";
        } elseif ($action === 'decline_service_change') {
            $set['status'] = 'Pending';
            $set['service_change_result'] = 'declined';

            $unset = [
                'requested_service_id' => '',
                'requested_weight_kg' => '',
                'requested_total_amount' => ''
            ];

            $_SESSION['message'] = "Service change declined for order #{$orderIdText}.";
        } else {
            $newStatus = trim((string) ($_POST['status'] ?? ''));

            $allowedStatuses = [
                'Pending',
                'Washing',
                'Drying',
                'Folding',
                'In Progress',
                'Ready for Pickup',
                'Ready for Pick-Up',
                'Picked Up',
                'Completed',
                'Cancelled'
            ];

            if (!in_array($newStatus, $allowedStatuses, true)) {
                throw new RuntimeException('Invalid order status.');
            }

            $set['status'] = $newStatus;
            $_SESSION['message'] = "Order #{$orderIdText} updated to {$newStatus}.";
        }

        $update = ['$set' => $set];

        if ($unset !== []) {
            $update['$unset'] = $unset;
        }

        $db->orders->updateOne(['_id' => $orderId], $update);
        header('Location: dashboard.php');
        exit();
    }
} catch (Throwable $e) {
    error_log('Admin order action error: ' . $e->getMessage());
    $message .= '<div class="alert alert-danger">' . h($e->getMessage()) . '</div>';
}

try {
    foreach ($db->orders->find([], ['sort' => ['created_at' => -1]]) as $document) {
        $order = asArray($document);

        if (!$order) {
            continue;
        }

        $customer = findById($db->users, $order['user_id'] ?? null);
        $service = findById($db->services, $order['service_id'] ?? null);
        $requestedService = findById($db->services, $order['requested_service_id'] ?? null);

        $order['order_id_text'] = (string) ($order['_id'] ?? '');
        $order['customer_name'] = $customer['full_name'] ?? '—';
        $order['service_name'] = $service['service_name'] ?? '—';
        $order['requested_service_name'] = $requestedService['service_name'] ?? '—';
        $orders[] = $order;
    }
} catch (Throwable $e) {
    error_log('Admin orders loading error: ' . $e->getMessage());
    $message .= '<div class="alert alert-danger">Orders could not be loaded: ' .
        h($e->getMessage()) .
        '</div>';
}

$totalOrders = count($orders);
$pendingOrders = 0;
$completedOrders = 0;
$totalRevenue = 0.0;
$serviceChangeRequests = 0;
$cancelRequests = 0;

foreach ($orders as $order) {
    $status = (string) ($order['status'] ?? 'Pending');

    if ($status === 'Pending') {
        $pendingOrders++;
    }

    if ($status === 'Completed') {
        $completedOrders++;
    }

    if ($status === 'Service Change Requested') {
        $serviceChangeRequests++;
    }

    if ($status === 'Cancellation Requested') {
        $cancelRequests++;
    }

    if (!in_array($status, ['Cancelled', 'Cancellation Requested'], true)) {
        $totalRevenue += (float) ($order['total_amount'] ?? 0);
    }
}

try {
    $pendingReservations = $db->reservations->countDocuments([
        'status' => 'Pending'
    ]);
} catch (Throwable $e) {
    $pendingReservations = 0;
    error_log('Pending reservation count error: ' . $e->getMessage());
}

$weekStart = new DateTimeImmutable('monday this week');

for ($index = 0; $index < 7; $index++) {
    $day = $weekStart->modify("+{$index} days");
    $weeklyLabels[] = $day->format('D M j');
    $start = new UTCDateTime($day->setTime(0, 0)->getTimestamp() * 1000);
    $end = new UTCDateTime($day->modify('+1 day')->setTime(0, 0)->getTimestamp() * 1000);
    $userIds = [];

    try {
        foreach ($db->orders->find([
            'created_at' => ['$gte' => $start, '$lt' => $end],
            'status' => ['$nin' => ['Cancelled', 'Cancellation Requested']]
        ]) as $order) {
            if (($order['user_id'] ?? null) instanceof ObjectId) {
                $userIds[(string) $order['user_id']] = true;
            }
        }

        foreach ($db->reservations->find([
            'created_at' => ['$gte' => $start, '$lt' => $end],
            'status' => ['$nin' => ['Cancelled', 'Rejected', 'Declined']]
        ]) as $reservation) {
            if (($reservation['user_id'] ?? null) instanceof ObjectId) {
                $userIds[(string) $reservation['user_id']] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('Weekly chart error: ' . $e->getMessage());
    }

    $weeklyCustomers[$index] = count($userIds);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - LaundryQ</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <div class="d-flex align-items-center gap-2">
            <a href="../index.php" class="btn btn-outline-light btn-sm">← Back to Home</a>
            <span class="navbar-brand mb-0 h1">🧺 LaundryQ Admin</span>
            <a href="reservations.php" class="btn btn-outline-light btn-sm">📅 Reservations</a>
        </div>
        <div>
            <span class="text-white me-3">Logged in as: <?= h($_SESSION['admin_name'] ?? 'Admin') ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <?= $message ?>

    <?php if ($pendingReservations > 0): ?>
        <div class="alert alert-info">
            <strong>📅 <?= h($pendingReservations) ?> pending reservation(s).</strong>
            <a href="reservations.php" class="ms-2">Review them here</a>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-3 col-6 mb-3">
            <div class="card shadow-sm text-center h-100"><div class="card-body">
                <h6 class="text-muted">Total Orders</h6>
                <h3><?= h($totalOrders) ?></h3>
            </div></div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="card shadow-sm text-center h-100"><div class="card-body">
                <h6 class="text-muted">Pending</h6>
                <h3 class="text-secondary"><?= h($pendingOrders) ?></h3>
            </div></div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="card shadow-sm text-center h-100"><div class="card-body">
                <h6 class="text-muted">Completed</h6>
                <h3 class="text-success"><?= h($completedOrders) ?></h3>
            </div></div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="card shadow-sm text-center h-100 bg-primary text-white"><div class="card-body">
                <h6>Total Revenue</h6>
                <h3>₱<?= number_format($totalRevenue, 2) ?></h3>
            </div></div>
        </div>
    </div>

    <?php if ($cancelRequests > 0 || $serviceChangeRequests > 0): ?>
        <div class="row mb-4">
            <?php if ($cancelRequests > 0): ?>
                <div class="col-md-6"><div class="alert alert-warning">
                    🔔 <?= h($cancelRequests) ?> cancellation request(s) waiting for review.
                </div></div>
            <?php endif; ?>
            <?php if ($serviceChangeRequests > 0): ?>
                <div class="col-md-6"><div class="alert alert-info">
                    🔄 <?= h($serviceChangeRequests) ?> service change request(s) waiting for review.
                </div></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><h5 class="mb-0">Weekly Customers in Laundry</h5></div>
        <div class="card-body"><canvas id="weeklyCustomerChart" height="100"></canvas></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><h5 class="mb-0">All Orders</h5></div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-sm">
                <thead class="table-light"><tr>
                    <th>Order ID</th><th>Customer</th><th>Service</th><th>Weight</th>
                    <th>Total</th><th>Status</th><th>Date</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php if (!$orders): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No orders found.</td></tr>
                <?php endif; ?>

                <?php foreach ($orders as $order): ?>
                    <?php
                    $orderId = $order['order_id_text'];
                    $status = (string) ($order['status'] ?? 'Pending');
                    $highlight = in_array($status, ['Cancellation Requested', 'Service Change Requested'], true)
                        ? 'table-warning'
                        : '';
                    ?>
                    <tr class="<?= h($highlight) ?>">
                        <td>#<?= h($orderId) ?></td>
                        <td><?= h($order['customer_name']) ?></td>
                        <td>
                            <?= h($order['service_name']) ?>
                            <?php if ($status === 'Service Change Requested'): ?>
                                <small class="d-block text-muted">Requested: <?= h($order['requested_service_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($order['weight_kg'] ?? null) ?> kg</td>
                        <td>₱<?= number_format((float) ($order['total_amount'] ?? 0), 2) ?></td>
                        <td><?= statusBadge($status) ?></td>
                        <td><?= formatDateValue($order['created_at'] ?? null) ?></td>
                        <td>
                            <?php if ($status === 'Cancellation Requested'): ?>
                                <form method="POST" action="dashboard.php" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                                    <input type="hidden" name="status" value="Cancelled">
                                    <button class="btn btn-danger btn-sm">Approve Cancel</button>
                                </form>
                                <form method="POST" action="dashboard.php" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                                    <input type="hidden" name="status" value="Pending">
                                    <button class="btn btn-outline-secondary btn-sm">Reject</button>
                                </form>
                            <?php elseif ($status === 'Service Change Requested'): ?>
                                <small class="d-block text-muted mb-1">
                                    → <?= h($order['requested_service_name']) ?>,
                                    <?= h($order['requested_weight_kg'] ?? null) ?> kg,
                                    ₱<?= number_format((float) ($order['requested_total_amount'] ?? 0), 2) ?>
                                </small>
                                <form method="POST" action="dashboard.php" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                                    <input type="hidden" name="action" value="approve_service_change">
                                    <button class="btn btn-info btn-sm">Approve</button>
                                </form>
                                <form method="POST" action="dashboard.php" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                                    <input type="hidden" name="action" value="decline_service_change">
                                    <button class="btn btn-outline-secondary btn-sm">Decline</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="dashboard.php" class="d-flex gap-2">
                                    <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                                    <select name="status" class="form-select form-select-sm">
                                        <?php foreach (['Pending', 'Washing', 'Drying', 'Folding', 'Ready for Pick-Up', 'Completed', 'Cancelled'] as $option): ?>
                                            <option value="<?= h($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-primary btn-sm">Update</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('weeklyCustomerChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($weeklyLabels) ?>,
        datasets: [{
            label: 'Customers',
            data: <?= json_encode($weeklyCustomers) ?>,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, .15)',
            fill: true,
            tension: .3
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
</script>
</body>
</html>
