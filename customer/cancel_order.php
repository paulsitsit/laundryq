<?php
// File: laundryq/customer/cancel_order.php

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

$orderIdString = trim(
    (string) (
        $_POST['order_id'] ??
        $_GET['order_id'] ??
        ''
    )
);

if (
    !preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $userIdString
    ) ||
    !preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $orderIdString
    )
) {
    die('Invalid user or order ID.');
}

$userId = new ObjectId($userIdString);
$orderId = new ObjectId($orderIdString);

try {
    $result = $db->orders->updateOne(
        [
            '_id' => $orderId,
            'user_id' => $userId,
            'status' => 'Pending'
        ],
        [
            '$set' => [
                'status' =>
                    'Cancellation Requested',
                'notified' => false,
                'updated_at' =>
                    new UTCDateTime()
            ]
        ]
    );

    if ($result->getModifiedCount() === 0) {
        $_SESSION['error'] =
            'This order cannot be cancelled or was already updated.';
    } else {
        $_SESSION['message'] =
            'Cancellation request submitted.';
    }
} catch (Throwable $e) {
    error_log(
        'Cancel order error: ' .
        $e->getMessage()
    );

    $_SESSION['error'] =
        'The cancellation request could not be submitted.';
}

header('Location: track_order.php');
exit;