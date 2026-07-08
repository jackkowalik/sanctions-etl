<?php

require __DIR__ . '/../vendor/autoload.php';

use SanctionsEtl\Config;
use SanctionsEtl\Log\ConsoleLogger;
use SanctionsEtl\Storage\JsonStore;
use SanctionsEtl\Storage\MysqlStore;
use SanctionsEtl\Sync;
use Psr\Log\LogLevel;

$onlySource = null;
$logLevel = LogLevel::INFO;
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-v' || $arg === '--verbose') {
        $logLevel = LogLevel::DEBUG;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif (str_starts_with($arg, '--source=')) {
        $onlySource = substr($arg, 9);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/sync.php [--source=<source_id>] [-v]\n";
        exit(0);
    } else {
        $onlySource = $arg;
    }
}

try {
    $config = Config::load(dirname(__DIR__));
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Configuration error: {$e->getMessage()}\n");
    exit(1);
}

$logger = new ConsoleLogger($logLevel);

if ($config->storage() === 'mysql') {
    try {
        $pdo = new PDO($config->dbDsn(), $config->dbUser(), $config->dbPass(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
        exit(1);
    }
    $store = new MysqlStore($pdo, $logger);
} else {
    $store = new JsonStore($config->outputDir(), $logger);
}

$sync = new Sync($config, $logger, $store);

exit($sync->execute($onlySource, $force));
