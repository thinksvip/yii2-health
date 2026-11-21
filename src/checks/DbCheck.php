<?php

namespace Xincheng\Health\checks;

use PDO;
use yii\db\Connection;
use Xincheng\Health\CheckResult;

class DbCheck extends BaseCheck
{
    protected function doCheck(): CheckResult
    {
        $name = $this->name ?: $this->id;

        $connection = $this->getComponent(['attributes' => $this->resolveAttributes()]);

        if (!$connection instanceof Connection) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'db component missing',
            ]);
        }

        $wasActive = $connection->getIsActive();
        $connection->open();
        try {
            $connection->createCommand('SELECT 1')->execute();
            $meta = ['dsn' => $this->sanitizeDsn($connection->dsn)];

            try {
                $meta['serverVersion'] = $connection->getSchema()->getServerVersion();
            } catch (\Throwable $e) {
                $meta['serverVersion'] = 'n/a';
            }

            return new CheckResult($this->id, $name, CheckResult::STATUS_OK, $meta);
        } finally {
            if (!$wasActive) {
                $connection->close();
            }
        }
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
        // 处理 DSN 参数形式的密码: password=xxx, pwd=xxx
        $dsn = preg_replace('/password=[^;]*/i', 'password=***', $dsn);
        $dsn = preg_replace('/pwd=[^;]*/i', 'pwd=***', $dsn);

        // 处理 URI 形式的密码: scheme://user:password@host
        $dsn = preg_replace('/:\/\/([^:]+):([^@]+)@/', '://$1:***@', $dsn);

        return $dsn;
    }
}
