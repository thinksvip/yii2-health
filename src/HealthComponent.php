<?php

namespace Xincheng\Health;

use Closure;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use Xincheng\Health\checks\DbCheck;

class HealthComponent extends Component
{
    public $checks = [];
    public $autoDiscover = true;
    public $autoRegisterRoutes = true;
    public $viewPath;

    public function init()
    {
        parent::init();
        $this->bootstrapDefaults();
    }

    public function runChecks(): array
    {
        return $this->runChecksFiltered(null);
    }

    public function runChecksByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), static function (string $id) {
            return $id !== '';
        }));

        if (empty($ids)) {
            return $this->runChecksFiltered(static function () {
                return false;
            });
        }

        $set = array_fill_keys($ids, true);

        return $this->runChecksFiltered(static function (HealthCheckInterface $check) use ($set) {
            return isset($set[$check->getId()]);
        });
    }

    public function runChecksFiltered(?callable $predicate = null): array
    {
        $startedAt = microtime(true);
        $checkObjects = $this->buildChecks();
        if ($predicate !== null) {
            $checkObjects = array_values(array_filter($checkObjects, $predicate));
        }
        $results = [];

        foreach ($checkObjects as $check) {
            $checkStartTime = microtime(true);
            $results[] = $check->run();

            // 记录单个检查的异常耗时告警（超过10秒）
            $checkDuration = microtime(true) - $checkStartTime;
            if ($checkDuration > 10) {
                Yii::warning(sprintf(
                    'Health check "%s" took %.2f seconds, which is unusually long',
                    $check->getId(),
                    $checkDuration
                ), __METHOD__);
            }
        }

        $status = $this->calculateStatus($results);

        return [
            'status' => $status,
            'timestamp' => time(),
            'duration' => round((microtime(true) - $startedAt) * 1000, 2),
            'checks' => array_map(static function (CheckResult $result) {
                return $result->toArray();
            }, $results),
        ];
    }

    public function formatText(array $report): string
    {
        $lines = [];
        $lines[] = sprintf('Overall: %s (%sms)', $report['status'], $report['duration']);
        foreach ($report['checks'] as $check) {
            $meta = $check['meta'] ?? [];
            $detail = $meta['reason'] ?? ($meta['error'] ?? '');
            if ($detail === '' && !empty($meta)) {
                $detail = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $lines[] = sprintf(
                '- %s: %s (%sms)%s',
                $check['id'],
                $check['status'],
                $check['duration'],
                $detail ? ' - ' . $detail : ''
            );
        }

        return implode(PHP_EOL, $lines);
    }

    public function getHttpStatusCode(string $status): int
    {
        return $status === CheckResult::STATUS_CRITICAL ? 503 : 200;
    }

    protected function bootstrapDefaults(): void
    {
        $configFile = dirname(__DIR__) . '/config/health.php';
        if (!is_file($configFile)) {
            return;
        }

        $config = require $configFile;
        $defaults = $config['checks'] ?? [];
        if (empty($this->checks)) {
            $this->checks = $defaults;
            return;
        }

        foreach ($this->checks as $id => $definition) {
            if (is_array($definition) && !isset($definition['class']) && !isset($definition['__class']) && isset($defaults[$id])) {
                $this->checks[$id] = $definition + $defaults[$id];
            }
        }
    }

    protected function buildChecks(): array
    {
        $definitions = $this->getCheckDefinitions();
        $checks = [];

        foreach ($definitions as $id => $definition) {
            $checks[] = $this->createCheck($id, $definition);
        }

        return $checks;
    }

    protected function getCheckDefinitions(): array
    {
        $definitions = $this->checks;
        $definitions = array_merge($definitions, $this->autoDiscoverChecks($definitions));
        foreach ($definitions as $id => &$definition) {
            if (!is_array($definition)) {
                throw new InvalidConfigException('Check definition must be an array');
            }
            $definition['id'] = $id;
            $definition['componentId'] = $definition['componentId'] ?? $id;
            $definition['name'] = $definition['name'] ?? ucfirst($id);
            if (!isset($definition['componentResolver'])) {
                $definition['componentResolver'] = $this->getComponentResolver();
                $definition['componentExists'] = $definition['componentExists'] ?? $this->getComponentExistsDetector();
            }
        }
        unset($definition);

        return $definitions;
    }

    protected function createCheck(string $id, array $definition): HealthCheckInterface
    {
        if (!isset($definition['class']) && !isset($definition['__class'])) {
            throw new InvalidConfigException("Health check {$id} is missing class definition");
        }

        /** @var HealthCheckInterface $check */
        $check = Yii::createObject($definition);

        return $check;
    }

    protected function calculateStatus(array $results): string
    {
        if (empty($results)) {
            return CheckResult::STATUS_SKIPPED;
        }

        $statuses = array_map(static function (CheckResult $result) {
            return $result->status;
        }, $results);

        if (in_array(CheckResult::STATUS_CRITICAL, $statuses, true)) {
            return CheckResult::STATUS_CRITICAL;
        }
        if (in_array(CheckResult::STATUS_WARNING, $statuses, true)) {
            return CheckResult::STATUS_WARNING;
        }
        if (count(array_unique($statuses)) === 1 && reset($statuses) === CheckResult::STATUS_SKIPPED) {
            return CheckResult::STATUS_SKIPPED;
        }

        return CheckResult::STATUS_OK;
    }

    protected function getComponentExistsDetector(): Closure
    {
        return static function (string $id): bool {
            if (!Yii::$app) {
                return false;
            }
            $definitions = Yii::$app->getComponents(true);

            return array_key_exists($id, $definitions) || Yii::$app->has($id, false);
        };
    }

    protected function getComponentResolver(): Closure
    {
        return static function (string $id, array $overrides = []) {
            if (!Yii::$app) {
                return null;
            }

            $definition = Yii::$app->getComponents(false)[$id] ?? Yii::$app->getComponents(true)[$id] ?? null;
            if (is_string($definition)) {
                $definition = ['class' => $definition];
            }
            if (is_array($definition) && isset($definition['class']) && !Yii::$container->has($definition['class'])) {
                if (isset($overrides['attributes']) && is_a($definition['class'], Connection::class, true)) {
                    $overrides['attributes'] += $definition['attributes'] ?? get_class_vars($definition['class'])['attributes'] ?? [];
                }
                return Yii::createObject(array_replace($definition, $overrides));
            }

            // Let the service locator invoke factories, then isolate the probe
            // from the instance the application (or the factory) may reuse.
            $component = Yii::$app->get($id, false);
            if (!is_object($component)) {
                return $component;
            }
            if ($component instanceof Connection && isset($overrides['attributes'])) {
                $overrides['attributes'] += $component->attributes ?? [];
            }
            try {
                $probe = clone $component;
            } catch (\Throwable $e) {
                throw new InvalidConfigException("Cannot isolate component \"$id\" for a health check", 0, $e);
            }

            return Yii::configure($probe, $overrides);
        };
    }

    protected function autoDiscoverChecks(array $existing): array
    {
        if (!$this->autoDiscover || !Yii::$app) {
            return [];
        }

        $components = array_replace(Yii::$app->getComponents(true), Yii::$app->getComponents(false));
        $auto = [];
        foreach ($components as $id => $definition) {
            if (isset($existing[$id]) || isset($auto[$id])) {
                continue;
            }
            if ($this->isDbComponent($definition)) {
                $auto[$id] = [
                    'class' => DbCheck::class,
                    'name' => sprintf('DB (%s)', $id),
                    'componentId' => $id,
                    'enabled' => 'auto',
                ];
            }
        }

        return $auto;
    }

    private function isDbComponent($definition): bool
    {
        if ($definition instanceof Connection) {
            return true;
        }
        if (is_array($definition) && isset($definition['class'])) {
            return is_a($definition['class'], Connection::class, true);
        }
        if (is_string($definition)) {
            return is_a($definition, Connection::class, true);
        }

        return false;
    }
}
