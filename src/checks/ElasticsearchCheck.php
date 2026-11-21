<?php

namespace Xincheng\Health\checks;

use yii\elasticsearch\Connection;
use Xincheng\Health\CheckResult;

class ElasticsearchCheck extends BaseCheck
{
    protected function doCheck(): CheckResult
    {
        $connection = $this->getComponent();
        $name = $this->name ?: $this->id;

        if (!$connection instanceof Connection) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'elasticsearch component missing',
            ]);
        }

        if ($connection->connectionTimeout === null || $connection->connectionTimeout > $this->timeout) {
            $connection->connectionTimeout = $this->timeout;
        }
        if ($connection->dataTimeout === null || $connection->dataTimeout > $this->timeout) {
            $connection->dataTimeout = $this->timeout;
        }

        $cluster = $connection->get('_cluster/health');
        $clusterStatus = is_string($cluster['status'] ?? null) ? strtolower($cluster['status']) : 'unknown';
        $statuses = [
            'green' => CheckResult::STATUS_OK,
            'yellow' => CheckResult::STATUS_WARNING,
            'red' => CheckResult::STATUS_CRITICAL,
        ];
        $status = $statuses[$clusterStatus] ?? CheckResult::STATUS_CRITICAL;

        return new CheckResult($this->id, $name, $status, [
            'clusterStatus' => $clusterStatus,
            'activeNode' => $connection->activeNode,
            'nodes' => $connection->nodes,
        ]);
    }
}
