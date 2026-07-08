<?php

namespace SanctionsEtl\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class ConsoleLogger extends AbstractLogger
{
    private const LEVEL_ORDER = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private int $minLevel;

    /** @var resource */
    private $stream;

    public function __construct(string $minLevel = LogLevel::INFO, $stream = null)
    {
        $this->minLevel = self::LEVEL_ORDER[$minLevel] ?? 1;
        $this->stream = $stream ?? fopen('php://stderr', 'w');
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $order = self::LEVEL_ORDER[$level] ?? 1;
        if ($order < $this->minLevel) {
            return;
        }

        $line = sprintf(
            "[%s] [%s] %s",
            date('Y-m-d H:i:s'),
            strtoupper((string) $level),
            $message
        );

        if ($context !== []) {
            $line .= ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        fwrite($this->stream, $line . "\n");
    }
}
