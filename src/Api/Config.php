<?php
namespace App\Api;

final class Config {
    public readonly string $dataDir;
    public readonly int $cacheTtl;
    public readonly int $sessionTtl;
    public readonly bool $allowHttp;
    public function __construct(?string $dataDir = null) {
        $this->dataDir = $dataDir ?? (getenv('IBUPROFEN_DATA_DIR') ?: dirname(__DIR__, 2) . '/var');
        $this->cacheTtl = max(60, (int)(getenv('IBUPROFEN_CACHE_TTL') ?: 3600));
        $this->sessionTtl = max(300, min(86400, (int)(getenv('IBUPROFEN_ADMIN_SESSION_TTL') ?: 28800)));
        $this->allowHttp = getenv('IBUPROFEN_ALLOW_HTTP') === '1';
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0700, true)) throw new \RuntimeException('Cannot create data directory');
        date_default_timezone_set('Europe/Ljubljana');
    }
}
