<?php

namespace Xincheng\Health\checks;

use Xincheng\Health\CheckResult;

class QueueCheck extends BaseCheck
{
    protected function doCheck(): CheckResult
    {
        $queue = $this->getComponent();
        $name = $this->name ?: $this->id;

        if ($queue === null) {
            return new CheckResult($this->id, $name, CheckResult::STATUS_CRITICAL, [
                'reason' => 'queue component missing',
            ]);
        }

        $meta = ['driver' => get_class($queue)];

        if (class_exists('\\yii\\queue\\redis\\Queue') && $queue instanceof \yii\queue\redis\Queue) {
            $redis = $queue->redis ?? null;
            if ($redis) {
                $redis->open();
                $redis->executeCommand('PING');
                $meta['backend'] = 'redis';
                $meta['host'] = $redis->hostname ?? null;
                return new CheckResult($this->id, $name, CheckResult::STATUS_OK, $meta);
            }
        }

        if (class_exists('\\yii\\queue\\db\\Queue') && $queue instanceof \yii\queue\db\Queue) {
            $db = $queue->db ?? null;
            if ($db) {
                $db->open();
                $db->createCommand('SELECT 1')->execute();
                $meta['backend'] = 'db';
                $meta['dsn'] = isset($db->dsn) ? $this->sanitizeDsn((string)$db->dsn) : null;
                return new CheckResult($this->id, $name, CheckResult::STATUS_OK, $meta);
            }
        }

        if (class_exists('\\yii\\queue\\Queue') && $queue instanceof \yii\queue\Queue) {
            $meta['reason'] = 'Queue component ready (no ping method available)';
            return new CheckResult($this->id, $name, CheckResult::STATUS_OK, $meta);
        }

        return new CheckResult($this->id, $name, CheckResult::STATUS_WARNING, [
            'reason' => 'Unknown queue driver, unable to ping backend',
            'driver' => get_class($queue),
        ]);
    }

    protected function sanitizeDsn(string $dsn): string
    {
        $dsn = preg_replace('/password=[^&;]*/i', 'password=***', $dsn);
        $dsn = preg_replace('/pwd=[^&;]*/i', 'pwd=***', $dsn);
        $dsn = preg_replace('/:\\/\\/([^:]+):([^@]+)@/', '://$1:***@', $dsn);

        return $dsn;
    }
}
