<?php
// File: laundryq/admin/reservations.php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if (!isset($_SESSION['admin_id'])) {
    die("You must be logged in as admin. <a href='login.php'>Login here</a>");
}

function h(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function objectIdString(mixed $value): string
{
    return $value instanceof ObjectId ? (string) $value : (string) ($value ?? '');
}

function findById($collection, mixed $id): ?array
{
    if (!$id instanceof ObjectId) {
        return null;
    }

    $document = $collection->findOne(['_id' => $id]);
    return $document ? $document->getArrayCopy() : null;
}

function dateValue(mixed $value): string
{
    if ($value instanceof UTCDateTime) {
        return $value->toDateTime()->format('Y-m-d H:i:s');
    }

    return (string) ($value ?? '');
}

function formatDateTime(mixed $date, mixed $time): string
{
    if (!$date || !$time) {
        return '<span class="text-muted">—</span>';
    }

    $timestamp = strtotime((string) $date . ' ' . (string) $time);

    if ($timestamp === false) {
        return h($date) . '<br>' . h($time);
    }

    return date('M j, Y', $timestamp) .
        '<br><small class="text-muted">' .
        date('g:i A', $timestamp) .
        '</small>';
}

function isOverdue(mixed $date, mixed $time): bool
{
    if (!$date || !$time) {
        return false;
    }

    $timestamp = strtotime((string) $date . ' ' . (string) $time);
    return $timestamp !== false && time() > $timestamp;
}

function badge(string $status): string
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
        'Rejected' => 'danger',
        'Declined' => 'danger',
        'Service Change Requested' => 'info'
    ];

    $color = $colors[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . h($status) . '</span>';
}

function sortLink(string $column, string $label, string $sort, string $order): string
{
    $nextOrder = $sort === $column && $order === 'asc' ? 'desc' : 'asc';
    $arrow = $sort === $column ? ($order === 'asc' ? ' ▲' : ' ▼') : '';

    return '<a class="text-decoration-none text-reset" href="?sort=' .
        urlencode($column) . '&order=' . urlencode($nextOrder) . '">' .
        h($label . $arrow) . '</a>';
}

$sort = $_GET['sort'] ?? 'latest';
$order = $_GET['order'] ?? 'desc';
$allowedSorts = ['id', 'customer', 'date', 'time', 'status', 'latest'];

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

if (!in_array($order, ['asc', 'desc'], true)) {
    $order = 'desc';
}

$message = '';

foreach (['message' => 'success', 'error' => 'danger'] as $sessionKey => $alertType) {
    if (isset($_SESSION[$sessionKey])) {
        $message .= '<div class="alert alert-' . $alertType .
            ' alert-dismissible fade show">' .
            h($_SESSION[$sessionKey]) .
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' .
            '</div>';
        unset($_SESSION[$sessionKey]);
    }
}

$reservations = [];
$loadError = '';

try {
    foreach ($db->reservations->find([]) as $document) {
        $reservation = $document->getArrayCopy();
        $user = findById($db->users, $reservation['user_id'] ?? null);
        $service = findById($db->services, $reservation['service_id'] ?? null);
        $requestedService = findById($db->services, $reservation['requested_service_id'] ?? null);

        $reservation['customer_name'] = $user['full_name'] ?? 'Unknown customer';
        $reservation['service_name'] = $service['service_name'] ?? '—';
        $reservation['requested_service_name'] = $requestedService['service_name'] ?? '—';
        $reservation['reservation_id'] = objectIdString($reservation['_id'] ?? null);
        $reservation['created_sort'] = dateValue($reservation['created_at'] ?? null);
        $reservations[] = $reservation;
    }

    usort($reservations, function (array $a, array $b) use ($sort, $order): int {
        if ($sort === 'id') {
            $left = $a['reservation_id'];
            $right = $b['reservation_id'];
        } elseif ($sort === 'customer') {
            $left = strtolower((string) $a['customer_name']);
            $right = strtolower((string) $b['customer_name']);
        } elseif ($sort === 'date') {
            $left = (string) ($a['reservation_date'] ?? '');
            $right = (string) ($b['reservation_date'] ?? '');
        } elseif ($sort === 'time') {
            $left = (string) ($a['reservation_time'] ?? '');
            $right = (string) ($b['reservation_time'] ?? '');
        } elseif ($sort === 'status') {
            $left = strtolower((string) ($a['status'] ?? ''));
            $right = strtolower((string) ($b['status'] ?? ''));
        } else {
            $left = $a['created_sort'];
            $right = $b['created_sort'];
        }

        $comparison = is_numeric($left) && is_numeric($right)
            ? ((float) $left <=> (float) $right)
            : strnatcasecmp((string) $left, (string) $right);

        return $order === 'asc' ? $comparison : -$comparison;
    });
} catch (Throwable $e) {
    error_log('Admin reservations loading error: ' . $e->getMessage());
    $loadError = 'Reservations could not be loaded.';
}

try {
    $serviceChangeCount = $db->reservations->countDocuments([
        'status' => 'Service Change Requested'
    ]);
} catch (Throwable $e) {
    error_log('Reservation count error: ' . $e->getMessage());
    $serviceChangeCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservations - LaundryQ Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .action-buttons { display: flex; gap: .25rem; flex-wrap: wrap; }
        .action-buttons form { display: inline; }
        .action-buttons .btn { font-size: .75rem; padding: .25rem .5rem; }
        @media (max-width: 992px) {
            .action-buttons { flex-direction: column; }
            .action-buttons .btn { width: 100%; font-size: .85rem; }
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-3">
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">← Back to Dashboard</a>
            <span class="navbar-brand mb-0 h1">📅 Reservations</span>
        </div>
        <div class="d-flex align-items-center">
            <span class="text-white me-3">Logged in as: <?= h($_SESSION['admin_name'] ?? 'Admin') ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid pb-5">
    <?= $message ?>

    <?php if ($loadError !== ''): ?>
        <div class="alert alert-danger"><?= h($loadError) ?></div>
    <?php endif; ?>

    <?php if ($serviceChangeCount > 0): ?>
        <div class="alert alert-info">
            <strong>🔄 <?= h($serviceChangeCount) ?> service change request(s)</strong>
            waiting for review.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">All Reservations</h5>
            <small class="text-muted">
                Sorted by: <?= h($sort === 'latest' ? 'Newest' : ucfirst($sort)) ?>
                (<?= h(strtoupper($order)) ?>)
            </small>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-sm">
                    <thead class="table-light">
                    <tr>
                        <th><?= sortLink('id', 'ID', $sort, $order) ?></th>
                        <th><?= sortLink('customer', 'Customer', $sort, $order) ?></th>
                        <th>Service</th>
                        <th>Est. Wt</th>
                        <th><?= sortLink('date', 'Drop-Off', $sort, $order) ?></th>
                        <th>Pick-Up</th>
                        <th><?= sortLink('status', 'Status', $sort, $order) ?></th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$reservations): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No reservations found.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($reservations as $reservation): ?>
                        <?php
                        $id = $reservation['reservation_id'];
                        $status = (string) ($reservation['status'] ?? 'Pending');
                        $overdue = isOverdue(
                            $reservation['reservation_date'] ?? null,
                            $reservation['reservation_time'] ?? null
                        );
                        $rowClass = $status === 'Pending'
                            ? 'table-warning'
                            : (in_array($status, ['No-Show', 'Rejected', 'Declined'], true)
                                ? 'table-danger'
                                : ($status === 'Service Change Requested' ? 'table-info' : ''));
                        ?>
                        <tr class="<?= h($rowClass) ?>">
                            <td><strong>#<?= h($id) ?></strong></td>
                            <td><?= h($reservation['customer_name'] ?? null) ?></td>
                            <td><?= h($reservation['service_name'] ?? null) ?></td>
                            <td><?= h($reservation['weight_kg'] ?? null) ?> kg</td>
                            <td><?= formatDateTime($reservation['reservation_date'] ?? null, $reservation['reservation_time'] ?? null) ?></td>
                            <td><?= formatDateTime($reservation['pickup_date'] ?? null, $reservation['pickup_time'] ?? null) ?></td>
                            <td><?= badge($status) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($status === 'Pending'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-success btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#acceptReservationModal"
                                            data-reservation-id="<?= h($id) ?>"
                                            data-customer="<?= h($reservation['customer_name'] ?? '') ?>"
                                            data-service="<?= h($reservation['service_name'] ?? '') ?>"
                                        >
                                            Accept
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rejectReservationModal"
                                            data-reservation-id="<?= h($id) ?>"
                                            data-customer="<?= h($reservation['customer_name'] ?? '') ?>"
                                            data-service="<?= h($reservation['service_name'] ?? '') ?>"
                                        >
                                            Reject
                                        </button>

                                    <?php elseif ($status === 'Service Change Requested'): ?>
                                        <small class="w-100 text-muted">
                                            → <?= h($reservation['requested_service_name'] ?? null) ?>,
                                            <?= h($reservation['requested_weight_kg'] ?? null) ?> kg
                                        </small>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="approve_service_change">
                                            <button class="btn btn-info btn-sm">Approve</button>
                                        </form>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="decline_service_change">
                                            <button class="btn btn-outline-secondary btn-sm">Decline</button>
                                        </form>

                                    <?php elseif ($status === 'Accepted'): ?>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="mark_arrived">
                                            <button class="btn btn-info btn-sm">Arrived</button>
                                        </form>
                                        <?php if ($overdue): ?>
                                            <form method="POST" action="handle_reservation_action.php">
                                                <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                                <input type="hidden" name="action" value="mark_no_show">
                                                <button class="btn btn-danger btn-sm">No-Show</button>
                                            </form>
                                        <?php endif; ?>

                                    <?php elseif ($status === 'Arrived'): ?>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="start_processing">
                                            <button class="btn btn-primary btn-sm">Start</button>
                                        </form>

                                    <?php elseif (in_array($status, ['In Progress', 'Washing', 'Drying', 'Folding'], true)): ?>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="mark_ready_pickup">
                                            <button class="btn btn-primary btn-sm">Ready for Pick-Up</button>
                                        </form>

                                    <?php elseif ($status === 'Ready for Pick-Up'): ?>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="mark_picked_up">
                                            <button class="btn btn-success btn-sm">Picked Up</button>
                                        </form>

                                    <?php elseif ($status === 'Picked Up'): ?>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="complete">
                                            <button class="btn btn-success btn-sm">Complete</button>
                                        </form>

                                    <?php elseif ($status === 'Reschedule Requested'): ?>
                                        <small class="w-100 text-muted">
                                            Requested: <?= h($reservation['requested_date'] ?? null) ?>
                                            <?= h($reservation['requested_time'] ?? null) ?>
                                        </small>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="accept_reschedule">
                                            <button class="btn btn-success btn-sm">Accept Reschedule</button>
                                        </form>
                                        <form method="POST" action="handle_reservation_action.php">
                                            <input type="hidden" name="reservation_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="action" value="reject_reschedule">
                                            <button class="btn btn-danger btn-sm">Reject Reschedule</button>
                                        </form>

                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="acceptReservationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Accept reservation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3"><div class="display-5">✅</div></div>
                <p class="text-center">Are you sure you want to accept this reservation?</p>
                <div class="bg-light rounded p-3">
                    <div class="row mb-2"><div class="col-5 text-muted">Reservation</div><div class="col-7 fw-semibold" id="acceptReservationId">—</div></div>
                    <div class="row mb-2"><div class="col-5 text-muted">Customer</div><div class="col-7 fw-semibold" id="acceptCustomerName">—</div></div>
                    <div class="row"><div class="col-5 text-muted">Service</div><div class="col-7 fw-semibold" id="acceptServiceName">—</div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="handle_reservation_action.php">
                    <input type="hidden" name="reservation_id" id="acceptReservationIdInput">
                    <input type="hidden" name="action" value="accept_pending">
                    <button type="submit" class="btn btn-success">✅ Yes, accept reservation</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectReservationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Reject reservation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3"><div class="display-5">⚠️</div></div>
                <p class="text-center">Are you sure you want to reject this reservation?</p>
                <div class="alert alert-warning mb-0">
                    Reservation <strong id="rejectReservationId">—</strong>
                    for <strong id="rejectCustomerName">the customer</strong>
                    will be marked as rejected.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep reservation</button>
                <form method="POST" action="handle_reservation_action.php">
                    <input type="hidden" name="reservation_id" id="rejectReservationIdInput">
                    <input type="hidden" name="action" value="reject_pending">
                    <button type="submit" class="btn btn-danger">Yes, reject reservation</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function fillReservationModal(event, prefix) {
        const button = event.relatedTarget;

        if (!button) {
            return;
        }

        const reservationId = button.getAttribute('data-reservation-id') || '';
        const customer = button.getAttribute('data-customer') || '—';
        const service = button.getAttribute('data-service') || '—';

        document.getElementById(prefix + 'ReservationId').textContent = '#' + reservationId;
        document.getElementById(prefix + 'ReservationIdInput').value = reservationId;

        if (prefix === 'accept') {
            document.getElementById('acceptCustomerName').textContent = customer;
            document.getElementById('acceptServiceName').textContent = service;
        }

        if (prefix === 'reject') {
            document.getElementById('rejectCustomerName').textContent = customer;
        }
    }

    document.getElementById('acceptReservationModal')?.addEventListener(
        'show.bs.modal',
        function (event) {
            fillReservationModal(event, 'accept');
        }
    );

    document.getElementById('rejectReservationModal')?.addEventListener(
        'show.bs.modal',
        function (event) {
            fillReservationModal(event, 'reject');
        }
    );
</script>
</body>
</html>
