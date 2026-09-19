<?php
// File: laundryq/config/db_connect.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;

function loadEnvironmentFile(string $filePath): void
{
    if (!is_file($filePath)) {
        return;
    }

    $lines = file(
        $filePath,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        $name = trim($name);
        $value = trim($value);

        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && $value[-1] === '"') ||
                ($value[0] === "'" && $value[-1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

loadEnvironmentFile(__DIR__ . '/../.env');

$mongoUri = getenv('MONGODB_URI');
$databaseName = getenv('MONGODB_DATABASE') ?: 'laundryq';

if (!$mongoUri) {
    http_response_code(500);

    exit(
        'MONGODB_URI is missing. ' .
        'Create the .env file in the project root.'
    );
}

try {
    $mongoClient = new Client(
        $mongoUri,
        [
            'serverSelectionTimeoutMS' => 10000,
            'connectTimeoutMS' => 10000
        ]
    );

    $db = $mongoClient->selectDatabase($databaseName);

    $db->command([
        'ping' => 1
    ])->toArray();
} catch (Throwable $e) {
    error_log(
        'MongoDB connection failed: ' .
        $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Database connection failed. ' .
        'Check your Atlas URI, credentials, and IP access list.'
    );
}