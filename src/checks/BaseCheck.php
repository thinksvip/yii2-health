<?php

namespace Xincheng\Health\checks;

use Closure;
use Xincheng\Health\CheckResult;
use Xincheng\Health\HealthCheckInterface;

abstract class BaseCheck implements HealthCheckInterface
{
    /** @var string */
    public $id;
    /** @var string */
    public $name;
    /** @var string */
    public $componentId;
    /** @var bool|string */
    public $enabled = 'auto';
    /** @var int|float */
    public $timeout = 3;
    /** @var Closure|null */
    public $componentResolver;
    /** @var Closure|null */
    public $componentExists;

    abstract protected function doCheck(): CheckResult;

    public function getId(): string
    {
        return $this->id;
    }

    public function run(): CheckResult
    {
        $startedAt = microtime(true);
        $name = $this->name ?: $this->id;

        if ($this->isDisabled()) {
            return $this->buildResult(CheckResult::STATUS_SKIPPED, ['reason' => 'disabled'], $startedAt, $name);
        }

        if ($this->enabled === 'auto' && !$this->isAvailable()) {
            return $this->buildResult(CheckResult::STATUS_SKIPPED, ['reason' => 'component not configured'], $startedAt, $name);
        }

        try {
            $result = $this->doCheck();
        } catch (\Throwable $e) {
            $result = new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'error' => $e->getMessage(),
            ]);
        }

        $result->duration = $this->formatDuration($startedAt);

        return $result;
    }

    protected function isDisabled(): bool
    {
        return in_array($this->enabled, [false, 0, '0', 'false', 'off', 'disabled'], true);
    }

    protected function buildResult(string $status, array $meta, float $startedAt, string $name): CheckResult
    {
        return new CheckResult($this->id, $name, $status, $meta, $this->formatDuration($startedAt));
    }

    protected function formatDuration(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    protected function isAvailable(): bool
    {
        $componentId = $this->componentId ?: $this->id;
        if ($this->componentExists instanceof Closure) {
            return (bool)call_user_func($this->componentExists, $componentId);
        }

        return $this->getComponent() !== null;
    }

    protected function getComponent(array $overrides = [])
    {
        $componentId = $this->componentId ?: $this->id;
        if ($this->componentResolver instanceof Closure) {
            try {
                return call_user_func($this->componentResolver, $componentId, $overrides);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
