<?php

require __DIR__ . '/../vendor/autoload.php';

use SanctionsEtl\Config;
use SanctionsEtl\Log\ConsoleLogger;
use SanctionsEtl\Storage\JsonStore;
use SanctionsEtl\Sync;
use Psr\Log\LogLevel;

$onlySource = null;
$logLevel = LogLevel::INFO;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-v' || $arg === '--verbose') {
        $logLevel = LogLevel::DEBUG;
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
    fwrite(STDERR, "MySQL storage backend is not implemented yet; set STORAGE=json\n");
    exit(1);
}

$store = new JsonStore($config->outputDir(), $logger);
$sync = new Sync($config, $logger, $store);

exit($sync->execute($onlySource));
