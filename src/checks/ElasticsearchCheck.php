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

        $cluster = $connection->get('_cluster/health', [], null, true);
        $clusterStatus = is_array($cluster) && isset($cluster['status']) ? strtolower((string)$cluster['status']) : null;

        if ($clusterStatus === 'red') {
            $status = CheckResult::STATUS_CRITICAL;
        } elseif ($clusterStatus === 'yellow') {
            $status = CheckResult::STATUS_WARNING;
        } else {
            $status = CheckResult::STATUS_OK;
        }

        return new CheckResult($this->id, $name, $status, [
            'clusterStatus' => $clusterStatus ?? 'unknown',
            'activeNode' => $connection->activeNode,
            'nodes' => $connection->nodes,
        ]);
    }
}
