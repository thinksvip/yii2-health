<?php

namespace Xincheng\Health\checks;

use yii\redis\Connection;
use Xincheng\Health\CheckResult;

class RedisCheck extends BaseCheck
{
    protected function doCheck(): CheckResult
    {
        $connection = $this->getComponent();
        $name = $this->name ?: $this->id;

        if (!$connection instanceof Connection) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'redis component missing',
            ]);
        }

        if ($connection->connectionTimeout === null || $connection->connectionTimeout > $this->timeout) {
            $connection->connectionTimeout = $this->timeout;
        }
        if ($connection->dataTimeout === null || $connection->dataTimeout > $this->timeout) {
            $connection->dataTimeout = $this->timeout;
        }

        $connection->open();
        $pong = $connection->executeCommand('PING');

        $isOk = $pong === true || strtoupper((string)$pong) === 'PONG';

        $status = $isOk ? CheckResult::STATUS_OK : CheckResult::STATUS_WARNING;

        return new CheckResult($this->id, $name, $status, [
            'reply' => $pong,
            'host' => $connection->hostname,
            'port' => $connection->port,
        ]);
    }
}
