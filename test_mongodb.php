<?php
// File: laundryq/test_mongodb.php

require_once __DIR__ . '/config/db_connect.php';

echo "MongoDB connection successful." . PHP_EOL;
echo "Database: " . $db->getDatabaseName() . PHP_EOL;

$collections = [];

foreach ($db->listCollections() as $collection) {
    $collections[] = $collection->getName();
}

if (empty($collections)) {
    echo "No collections exist yet." . PHP_EOL;
} else {
    echo "Collections:" . PHP_EOL;

    foreach ($collections as $collectionName) {
        echo "- " . $collectionName . PHP_EOL;
    }
}