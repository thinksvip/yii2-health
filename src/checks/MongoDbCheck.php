<?php

namespace Xincheng\Health\checks;

use MongoDB\Driver\Command;
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

        $connection->options = array_merge($connection->options ?? [], $this->buildOptions());
        $connection->open();
        $database = $connection->defaultDatabaseName ?: 'admin';
        $command = new Command(['ping' => 1]);
        $connection->manager->executeCommand($database, $command);

        return new CheckResult($this->id, $name, CheckResult::STATUS_OK, [
            'database' => $database,
            'dsn' => $connection->dsn,
        ]);
    }

    protected function buildOptions(): array
    {
        $timeout = (int)$this->timeout * 1000;
        return [
            'connectTimeoutMS' => $timeout,
            'socketTimeoutMS' => $timeout,
        ];
    }
}
