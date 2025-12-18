<?php

namespace Xincheng\Health\checks;

use Yii;
use Xincheng\Health\CheckResult;

class KafkaCheck extends BaseCheck
{
    /** @var array|string|null list of broker endpoints host:port */
    public $brokers;
    /** @var string|null optional topic to include in metadata lookup */
    public $topic;

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
        $brokers = $this->resolveBrokers();
        $name = $this->name ?: $this->id;

        if (empty($brokers)) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_SKIPPED, [
                'reason' => 'no brokers configured',
            ]);
        }

        if (class_exists('\\RdKafka\\Producer')) {
            try {
                $meta = $this->checkWithRdkafka($brokers);
                return new CheckResult($this->id, $name, CheckResult::STATUS_OK, $meta);
            } catch (\Throwable $e) {
                return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                    'error' => $e->getMessage(),
                    'brokers' => $brokers,
                ]);
            }
        }

        // Fallback: simple TCP ping first broker
        [$host, $port] = $this->splitHostPort($brokers[0]);
        $address = sprintf('tcp://%s:%d', $host, $port);
        $context = stream_context_create([
            'socket' => ['connect_timeout' => $this->timeout],
        ]);
        $fp = @stream_socket_client($address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'error' => trim($errstr ?: $errno),
                'brokers' => $brokers,
            ]);
        }
        fclose($fp);

        return new CheckResult($this->id, $name, CheckResult::STATUS_OK, [
            'brokers' => $brokers,
            'metadata' => 'socket ok (rdkafka extension not installed)',
        ]);
    }

    protected function isAvailable(): bool
    {
        if (parent::isAvailable()) {
            return true;
        }
        return !empty($this->resolveBrokers());
    }

    private function resolveBrokers(): array
    {
        if (!empty($this->brokers)) {
            return $this->normalizeBrokerList($this->brokers);
        }

        $component = $this->getComponent();

        if (is_object($component)) {
            if (method_exists($component, 'getBrokers')) {
                try {
                    $brokers = $component->getBrokers();
                    $list = $this->normalizeBrokerList($brokers);
                    if (!empty($list)) {
                        return $list;
                    }
                } catch (\Throwable $e) {
                    // ignore and continue
                }
            }
            if (property_exists($component, 'brokers')) {
                $list = $this->normalizeBrokerList($component->brokers);
                if (!empty($list)) {
                    return $list;
                }
            }
            if (property_exists($component, 'bootstrapServers')) {
                $list = $this->normalizeBrokerList($component->bootstrapServers);
                if (!empty($list)) {
                    return $list;
                }
            }
            if (method_exists($component, 'getBootstrapServers')) {
                try {
                    $list = $this->normalizeBrokerList($component->getBootstrapServers());
                    if (!empty($list)) {
                        return $list;
                    }
                } catch (\Throwable $e) {
                    // ignore and continue
                }
            }
        }

        if (is_array($component)) {
            $list = $this->normalizeBrokerList(
                $component['brokers']
                    ?? $component['bootstrapServers']
                    ?? $component['bootstrap_servers']
                    ?? null
            );
            if (!empty($list)) {
                return $list;
            }
        }

        return $this->resolveBrokersFromParams();
    }

    private function resolveBrokersFromParams(): array
    {
        if (!Yii::$app) {
            return [];
        }

        $params = Yii::$app->params ?? [];
        if (!is_array($params)) {
            return [];
        }

        $kafka = $params['kafka'] ?? null;
        if (is_string($kafka) || is_array($kafka)) {
            $list = $this->normalizeBrokerList(
                is_array($kafka)
                    ? ($kafka['brokers'] ?? $kafka['bootstrapServers'] ?? $kafka['bootstrap_servers'] ?? null)
                    : $kafka
            );
            if (!empty($list)) {
                return $list;
            }
        }

        $direct = $params['kafkaBrokers'] ?? $params['kafka_brokers'] ?? null;
        if (is_string($direct) || is_array($direct)) {
            return $this->normalizeBrokerList($direct);
        }

        return [];
    }

    private function checkWithRdkafka(array $brokers): array
    {
        $producer = new \RdKafka\Producer();
        $producer->addBrokers(implode(',', $brokers));

        $topic = null;
        if (!empty($this->topic)) {
            $topic = $producer->newTopic($this->topic);
        }

        $metadata = $producer->getMetadata(true, $topic, (int)($this->timeout * 1000));

        $brokerCount = count($metadata->getBrokers());
        $topicCount = count($metadata->getTopics());

        return [
            'brokers' => $brokers,
            'brokerCount' => $brokerCount,
            'topicCount' => $topicCount,
        ];
    }

    /**
     * @param array|string|null $brokers
     * @return string[]
     */
    private function normalizeBrokerList($brokers): array
    {
        if ($brokers === null || $brokers === '') {
            return [];
        }

        $list = [];
        if (is_array($brokers)) {
            $list = $brokers;
        } elseif (is_string($brokers)) {
            $list = preg_split('/\\s*,\\s*/', trim($brokers), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            return [];
        }

        $normalized = [];
        foreach ($list as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function splitHostPort(string $broker): array
    {
        if (strpos($broker, ':') !== false) {
            [$h, $p] = explode(':', $broker, 2);
            return [$h, (int)$p];
        }
        return [$broker, 9092];
    }
}
