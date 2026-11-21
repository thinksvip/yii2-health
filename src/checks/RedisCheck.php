<?php

namespace Xincheng\Health\checks;

use ReflectionClass;
use yii\base\InvalidConfigException;
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

        $flags = $connection->socketClientFlags ?? 0;
        if ($connection->getIsActive() || ($flags & STREAM_CLIENT_PERSISTENT)) {
            $connection = clone $connection;
            if ($connection->getIsActive()) {
                $this->detachSocket($connection);
            }
            if ($flags & STREAM_CLIENT_PERSISTENT) {
                $connection->socketClientFlags = ($flags & ~STREAM_CLIENT_PERSISTENT) | STREAM_CLIENT_CONNECT;
            }
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

    private function detachSocket(Connection $probe): void
    {
        // Yii Redis clones share the transport. close() would send QUIT to the host's socket.
        // Reset only the driver's transport on the clone, preserving subclass configuration.
        $driver = new ReflectionClass(Connection::class);
        $defaults = $driver->getDefaultProperties();
        foreach (['_pool', '_socket'] as $field) {
            if (array_key_exists($field, $defaults)) {
                $property = $driver->getProperty($field);
                if (PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                $property->setValue($probe, $defaults[$field]);
                break;
            }
        }
        if ($probe->getIsActive()) {
            throw new InvalidConfigException('Cannot isolate the active Redis connection for a health check.');
        }
    }
}
