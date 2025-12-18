<?php

namespace Xincheng\Health\checks;

use Yii;
use Xincheng\Health\CheckResult;

class TdengineCheck extends BaseCheck
{
    /** @var string|null */
    public $host;
    /** @var int|null */
    public $port;

    public function run(): CheckResult
    {
        if ($this->isDisabled()) {
            $startedAt = microtime(true);
            return $this->buildResult(CheckResult::STATUS_SKIPPED, ['reason' => 'disabled'], $startedAt, $this->name ?: $this->id);
        }

        return parent::run();
    }

    protected function doCheck(): CheckResult
    {
        $target = $this->resolveTarget();
        $name = $this->name ?: $this->id;

        if ($target === null) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_SKIPPED, [
                'reason' => 'tdengine host/port not configured',
            ]);
        }

        [$host, $port] = $target;
        $address = sprintf('tcp://%s:%d', $host, $port);
        $context = stream_context_create([
            'socket' => ['connect_timeout' => $this->timeout],
        ]);

        $fp = @stream_socket_client($address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'unreachable',
                'error' => trim($errstr ?: $errno),
                'host' => $host,
                'port' => $port,
            ]);
        }

        fclose($fp);

        return new CheckResult($this->id, $name, CheckResult::STATUS_OK, [
            'host' => $host,
            'port' => $port,
        ]);
    }

    protected function isAvailable(): bool
    {
        if (parent::isAvailable()) {
            return true;
        }
        $target = $this->resolveTarget();
        return $target !== null;
    }

    private function resolveTarget(): ?array
    {
        if ($this->host && $this->port) {
            return [$this->host, (int)$this->port];
        }

        $component = $this->getComponent();

        if (is_object($component) && property_exists($component, 'host') && property_exists($component, 'port')) {
            return [$component->host, (int)$component->port];
        }

        if (is_array($component)) {
            $host = $component['host'] ?? null;
            $port = $component['port'] ?? null;
            if ($host && $port) {
                return [$host, (int)$port];
            }
            $dsn = $component['dsn'] ?? null;
            if ($dsn && ($parsed = $this->parseDsn($dsn))) {
                return $parsed;
            }
        }

        if (is_object($component) && property_exists($component, 'dsn')) {
            if ($parsed = $this->parseDsn($component->dsn)) {
                return $parsed;
            }
        }

        return $this->resolveTargetFromParams();
    }

    private function resolveTargetFromParams(): ?array
    {
        if (!Yii::$app) {
            return null;
        }

        $params = Yii::$app->params ?? [];
        if (!is_array($params)) {
            return null;
        }

        $td = $params['tdengine'] ?? $params['taos'] ?? null;
        if (is_array($td)) {
            $host = $td['host'] ?? null;
            $port = $td['port'] ?? null;
            if ($host && $port) {
                return [$host, (int)$port];
            }
            $dsn = $td['dsn'] ?? null;
            if (is_string($dsn) && ($parsed = $this->parseDsn($dsn))) {
                return $parsed;
            }
        } elseif (is_string($td)) {
            $td = trim($td);
            if ($td !== '') {
                if ($parsed = $this->parseDsn($td)) {
                    return $parsed;
                }
                if (preg_match('/^([^:]+):([0-9]+)$/', $td, $m)) {
                    return [$m[1], (int)$m[2]];
                }
            }
        }

        $host = $params['tdengineHost'] ?? $params['tdengine_host'] ?? null;
        $port = $params['tdenginePort'] ?? $params['tdengine_port'] ?? null;
        if ($host && $port) {
            return [(string)$host, (int)$port];
        }

        return null;
    }

    private function parseDsn(string $dsn): ?array
    {
        // e.g. taos://host:6041/db or tdengine:host=...;port=...
        if (stripos($dsn, '://') !== false) {
            $parts = parse_url($dsn);
            if (!empty($parts['host']) && !empty($parts['port'])) {
                return [$parts['host'], (int)$parts['port']];
            }
        }

        if (preg_match('/host=([^;]+).*port=([0-9]+)/i', $dsn, $m)) {
            return [$m[1], (int)$m[2]];
        }

        return null;
    }
}
