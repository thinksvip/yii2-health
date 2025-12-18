<?php

namespace Xincheng\Health\checks;

use mikemadisonweb\rabbitmq\Configuration;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Xincheng\Health\CheckResult;

class RabbitMqCheck extends BaseCheck
{
    protected function doCheck(): CheckResult
    {
        $component = $this->getComponent();
        $name = $this->name ?: $this->id;

        if (!$component instanceof Configuration) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'rabbitmq component missing',
            ]);
        }

        $config = $component->getConfig();
        $connectionConfig = $this->pickConnection($config->connections ?? []);

        if ($connectionConfig === null) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_SKIPPED, [
                'reason' => 'No RabbitMQ connections defined',
            ]);
        }

        $connection = $this->openConnection($connectionConfig);
        try {
            $channel = $connection->channel();
            $channel->close();
        } finally {
            if ($connection instanceof AbstractConnection) {
                try {
                    $connection->close();
                } catch (\Throwable $e) {
                    // ignore close failures
                }
            }
        }

        return new CheckResult($this->id, $name, CheckResult::STATUS_OK, [
            'connection' => $connectionConfig['name'] ?? 'default',
            'host' => $connectionConfig['host'] ?? null,
            'port' => $connectionConfig['port'] ?? null,
        ]);
    }

    protected function pickConnection(array $connections): ?array
    {
        foreach ($connections as $connection) {
            if (!empty($connection['host'])) {
                return $connection;
            }
        }

        return $connections[0] ?? null;
    }

    protected function openConnection(array $config): AbstractConnection
    {
        $type = $config['type'] ?? AMQPLazyConnection::class;
        if (!class_exists($type)) {
            $type = AMQPStreamConnection::class;
        }

        $host = $config['host'] ?? 'localhost';
        $port = (int)($config['port'] ?? 5672);
        $user = $config['user'] ?? 'guest';
        $password = $config['password'] ?? 'guest';
        $vhost = $config['vhost'] ?? '/';
        $connectionTimeout = $this->shortTimeout($config['connection_timeout'] ?? null);
        $readWriteTimeout = $this->shortTimeout($config['read_write_timeout'] ?? null);
        $sslContext = $config['ssl_context'] ?? null;
        if ($sslContext === 'null') {
            $sslContext = null;
        }
        $keepalive = isset($config['keepalive']) ? filter_var($config['keepalive'], FILTER_VALIDATE_BOOLEAN) : false;
        $heartbeat = isset($config['heartbeat']) ? (int)$config['heartbeat'] : 0;
        $channelRpcTimeout = isset($config['channel_rpc_timeout']) ? (float)$config['channel_rpc_timeout'] : 0.0;

        return new $type(
            $host,
            $port,
            $user,
            $password,
            $vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $connectionTimeout,
            $readWriteTimeout,
            $sslContext,
            $keepalive,
            $heartbeat,
            $channelRpcTimeout
        );
    }

    private function shortTimeout($value): float
    {
        $timeout = $value !== null ? (float)$value : (float)$this->timeout;
        $limit = $this->timeout ?: $timeout;

        return max(1, min($timeout, $limit));
    }
}
