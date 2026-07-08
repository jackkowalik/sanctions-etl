<?php

namespace SanctionsEtl;

use Dotenv\Dotenv;

class Config
{
    public const USER_AGENT = 'sanctions-etl/1.0 (+https://github.com/jackkowalik/sanctions-etl)';

    private const DEFAULTS = [
        'STORAGE'        => 'json',
        'OUTPUT_DIR'     => './out',
        'DB_HOST'        => '127.0.0.1',
        'DB_PORT'        => '3306',
        'DB_NAME'        => '',
        'DB_USER'        => '',
        'DB_PASS'        => '',
        'DOWNLOAD_DIR'   => './downloads',
        'KEEP_DOWNLOADS' => 'false',
        'SAM_API_KEY'    => '',
    ];

    /** @var array<string, string> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $projectRoot): self
    {
        Dotenv::createImmutable($projectRoot)->safeLoad();

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = self::env($key) ?? $default;
        }

        foreach (['OUTPUT_DIR', 'DOWNLOAD_DIR'] as $key) {
            $values[$key] = self::resolvePath($values[$key], $projectRoot);
        }

        $config = new self($values);
        $config->validate();

        return $config;
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === '') {
            return null;
        }
        return (string) $value;
    }

    private static function resolvePath(string $path, string $root): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return rtrim($root, '/') . '/' . rtrim($path, '/');
    }

    private function validate(): void
    {
        $storage = $this->storage();

        if (!in_array($storage, ['json', 'mysql'], true)) {
            throw new \RuntimeException(
                "Invalid STORAGE value '{$storage}': expected 'json' or 'mysql'"
            );
        }

        if ($storage === 'mysql') {
            $missing = [];
            foreach (['DB_NAME', 'DB_USER'] as $key) {
                if ($this->values[$key] === '') {
                    $missing[] = $key;
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException(
                    'STORAGE=mysql requires ' . implode(', ', $missing)
                    . ' to be set in the environment or .env'
                );
            }
        }
    }

    public function storage(): string
    {
        return strtolower($this->values['STORAGE']);
    }

    public function outputDir(): string
    {
        return $this->values['OUTPUT_DIR'];
    }

    public function downloadDir(): string
    {
        return $this->values['DOWNLOAD_DIR'];
    }

    public function keepDownloads(): bool
    {
        return filter_var($this->values['KEEP_DOWNLOADS'], FILTER_VALIDATE_BOOL);
    }

    public function samApiKey(): ?string
    {
        return $this->values['SAM_API_KEY'] !== '' ? $this->values['SAM_API_KEY'] : null;
    }

    public function dbDsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->values['DB_HOST'],
            $this->values['DB_PORT'],
            $this->values['DB_NAME']
        );
    }

    public function dbUser(): string
    {
        return $this->values['DB_USER'];
    }

    public function dbPass(): string
    {
        return $this->values['DB_PASS'];
    }
}
