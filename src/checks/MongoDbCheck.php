<?php

namespace Xincheng\Health\checks;

use MongoDB\Driver\Command;
use yii\base\InvalidConfigException;
use yii\mongodb\Connection;
use Xincheng\Health\CheckResult;

class MongoDbCheck extends BaseCheck
{
    protected function doCheck(): CheckResult
    {
        $connection = $this->getComponent();
        $name = $this->name ?: $this->id;

        if (!$connection instanceof Connection) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'mongodb component missing',
            ]);
        }

        foreach ($this->buildOptions() as $option => $limit) {
            $configured = (int)($connection->options[$option] ?? 0);
            $connection->options[$option] = $configured > 0 ? min($configured, $limit) : $limit;
        }
        $connection->open();
        try {
            $database = $connection->getDefaultDatabaseName() ?: 'admin';
        } catch (InvalidConfigException $e) {
            // The Yii driver throws for valid connection URIs without a database path.
            $database = 'admin';
        }
        $command = new Command(['ping' => 1]);
        $connection->manager->executeCommand($database, $command);

        return new CheckResult($this->id, $name, CheckResult::STATUS_OK, [
            'database' => $database,
            'dsn' => $this->sanitizeDsn((string)$connection->dsn),
        ]);
    }

    protected function buildOptions(): array
    {
        $timeout = max(1, (int)round($this->timeout * 1000));
        return [
            'connectTimeoutMS' => $timeout,
            'socketTimeoutMS' => $timeout,
            'serverSelectionTimeoutMS' => $timeout,
        ];
    }

    protected function sanitizeDsn(string $dsn): string
    {
        $dsn = preg_replace('/password=[^&;]*/i', 'password=***', $dsn);
        $dsn = preg_replace('/pwd=[^&;]*/i', 'pwd=***', $dsn);
        $dsn = preg_replace('/:\\/\\/([^:]+):([^@]+)@/', '://$1:***@', $dsn);

        return $dsn;
    }
}
