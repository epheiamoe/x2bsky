<?php
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Database;

try {
    Config::init(__DIR__ . '/.env');
    echo "Config loaded\n";

    Database::getInstance();
    echo "Database initialized\n";

    echo "All checks passed!\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
