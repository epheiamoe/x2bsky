<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;
use X2BSky\Database;
use X2BSky\Settings;

Config::init(__DIR__ . '/.env');
Database::resetInstance();
Database::getInstance();
Settings::initDefaults();
echo "Done\n";
