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

    public function runChecksFiltered(callable $predicate = null): array
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
        if (!empty($this->checks)) {
            return;
        }

        $configFile = dirname(__DIR__) . '/config/health.php';
        if (!is_file($configFile)) {
            return;
        }

        $config = require $configFile;
        if (isset($config['checks']) && is_array($config['checks'])) {
            $this->checks = $config['checks'];
        }
        if (isset($config['autoDiscover'])) {
            $this->autoDiscover = $config['autoDiscover'];
        }
        if (isset($config['autoRegisterRoutes'])) {
            $this->autoRegisterRoutes = $config['autoRegisterRoutes'];
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
            $definition['componentResolver'] = $this->getComponentResolver();
            $definition['componentExists'] = $this->getComponentExistsDetector();
        }
        unset($definition);

        return $definitions;
    }

    protected function createCheck(string $id, array $definition): HealthCheckInterface
    {
        if (!isset($definition['class'])) {
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

            $definitions = Yii::$app->getComponents(true);
            if (array_key_exists($id, $definitions)) {
                $definition = $definitions[$id];
                if (is_array($definition)) {
                    $definition = array_merge($definition, $overrides);
                    return Yii::createObject($definition);
                }
                if (is_object($definition)) {
                    try {
                        $clone = clone $definition;
                        foreach ($overrides as $property => $value) {
                            $clone->$property = $value;
                        }
                        return $clone;
                    } catch (\Throwable $e) {
                        Yii::warning(sprintf(
                            'Failed to clone component "%s": %s. Using original instance.',
                            $id,
                            $e->getMessage()
                        ), __METHOD__);
                        return $definition;
                    }
                }
            }

            if (Yii::$app->has($id)) {
                $component = Yii::$app->get($id);
                if (is_object($component) && !empty($overrides)) {
                    try {
                        $component = clone $component;
                        foreach ($overrides as $property => $value) {
                            $component->$property = $value;
                        }
                    } catch (\Throwable $e) {
                        Yii::warning(sprintf(
                            'Failed to clone component "%s": %s. Using original instance.',
                            $id,
                            $e->getMessage()
                        ), __METHOD__);
                        // use original component
                    }
                }
                return $component;
            }

            return null;
        };
    }

    protected function autoDiscoverChecks(array $existing): array
    {
        if (!$this->autoDiscover || !Yii::$app) {
            return [];
        }

        $components = Yii::$app->getComponents(true);
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
