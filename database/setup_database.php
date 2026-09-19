<?php
// database/setup_database.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoException;

function createIndexSafely($collection, array $keys, array $options = []): void
{
    try {
        $indexName = $collection->createIndex($keys, $options);

        echo "Created or confirmed index: {$indexName}" . PHP_EOL;
    } catch (MongoException $e) {
        echo "Index warning: " . $e->getMessage() . PHP_EOL;
    }
}

try {
    echo "Setting up LaundryQ MongoDB database..." . PHP_EOL;

    /*
     * Collections are created automatically when the first document is
     * inserted. Creating them explicitly makes the setup easier to verify.
     */
    $existingCollections = [];

    foreach ($db->listCollections() as $collectionInfo) {
        $existingCollections[] = $collectionInfo->getName();
    }

    $collections = [
        'users',
        'services',
        'holidays',
        'reservations',
        'orders'
    ];

    foreach ($collections as $collectionName) {
        if (!in_array($collectionName, $existingCollections, true)) {
            $db->createCollection($collectionName);
            echo "Created collection: {$collectionName}" . PHP_EOL;
        }
    }

    /*
     * USERS
     */

    createIndexSafely(
        $db->users,
        ['email' => 1],
        [
            'unique' => true,
            'name' => 'uq_users_email'
        ]
    );

    /*
     * SERVICES
     */

    createIndexSafely(
        $db->services,
        ['service_name' => 1],
        [
            'name' => 'idx_services_service_name'
        ]
    );

    createIndexSafely(
        $db->services,
        ['is_active' => 1],
        [
            'name' => 'idx_services_is_active'
        ]
    );

    /*
     * HOLIDAYS
     */

    createIndexSafely(
        $db->holidays,
        ['holiday_date' => 1],
        [
            'unique' => true,
            'name' => 'uq_holidays_date'
        ]
    );

    /*
     * RESERVATIONS
     */

    createIndexSafely(
        $db->reservations,
        ['user_id' => 1],
        [
            'name' => 'idx_reservations_user_id'
        ]
    );

    createIndexSafely(
        $db->reservations,
        ['status' => 1],
        [
            'name' => 'idx_reservations_status'
        ]
    );

    createIndexSafely(
        $db->reservations,
        ['reservation_date' => 1],
        [
            'name' => 'idx_reservations_date'
        ]
    );

    createIndexSafely(
        $db->reservations,
        ['created_at' => -1],
        [
            'name' => 'idx_reservations_created_at'
        ]
    );

    createIndexSafely(
        $db->reservations,
        [
            'reservation_date' => 1,
            'reservation_time' => 1
        ],
        [
            'name' => 'idx_reservations_schedule'
        ]
    );

    createIndexSafely(
        $db->reservations,
        [
            'user_id' => 1,
            'notified' => 1
        ],
        [
            'name' => 'idx_reservations_notifications'
        ]
    );

    /*
     * ORDERS
     */

    createIndexSafely(
        $db->orders,
        ['user_id' => 1],
        [
            'name' => 'idx_orders_user_id'
        ]
    );

    createIndexSafely(
        $db->orders,
        ['status' => 1],
        [
            'name' => 'idx_orders_status'
        ]
    );

    createIndexSafely(
        $db->orders,
        ['created_at' => -1],
        [
            'name' => 'idx_orders_created_at'
        ]
    );

    /*
     * Email verification cleanup.
     *
     * The users collection is intentionally not deleted automatically.
     * Only users with a verification token can be removed by this job.
     */
    createIndexSafely(
        $db->users,
        ['verification_token_expires_at' => 1],
        [
            'expireAfterSeconds' => 0,
            'partialFilterExpression' => [
                'email_verified_at' => null,
                'verification_token' => [
                    '$exists' => true
                ]
            ],
            'name' => 'ttl_unverified_users'
        ]
    );

    echo PHP_EOL;
    echo "MongoDB setup completed successfully." . PHP_EOL;
    echo "Database: " . ($db->getDatabaseName()) . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);

    echo PHP_EOL;
    echo "MongoDB setup failed:" . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;

    exit(1);
}