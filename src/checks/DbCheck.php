<?php

namespace Xincheng\Health\checks;

use PDO;
use yii\db\Connection;
use Xincheng\Health\CheckResult;

class DbCheck extends BaseCheck
{
    protected function doCheck(): CheckResult
    {
        $connection = $this->getComponent();

        $name = $this->name ?: $this->id;

        if (!$connection instanceof Connection) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'db component missing',
            ]);
        }

        $connection->attributes = array_replace(is_array($connection->attributes) ? $connection->attributes : [], $this->resolveAttributes());
        $connection->open();
        $connection->createCommand('SELECT 1')->execute();

        $server = $connection->dsn;
        $meta = [
            'dsn' => $this->sanitizeDsn($server),
        ];

        try {
            $meta['serverVersion'] = $connection->getSchema()->getServerVersion();
        } catch (\Throwable $e) {
            $meta['serverVersion'] = 'n/a';
        }

        $connection->close();

        return new CheckResult($this->id, $name, CheckResult::STATUS_OK, $meta);
    }

    protected function resolveAttributes(): array
    {
        $attributes = [
            PDO::ATTR_TIMEOUT => (int)$this->timeout,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];

        return $attributes;
    }

    protected function sanitizeDsn(string $dsn): string
    {
        return preg_replace('/password=[^;]*/i', 'password=***', $dsn);
    }
}
