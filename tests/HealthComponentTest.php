<?php

use PHPUnit\Framework\TestCase;
use Xincheng\Health\CheckResult;
use Xincheng\Health\checks\DbCheck;
use Xincheng\Health\HealthComponent;
use yii\console\Application;
use yii\db\Connection;
use yii\di\Container;

class HealthComponentTest extends TestCase
{
    protected function setUp(): void
    {
        $this->mockApplication();
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
        Yii::$container = new Container();
    }

    public function testDbCheckSuccess(): void
    {
        Yii::$app->set('db', ['class' => StubDbConnection::class]);
        $component = new HealthComponent([
            'checks' => [
                'db' => [
                    'class' => DbCheck::class,
                    'componentId' => 'db',
                    'timeout' => 1,
                ],
            ],
        ]);

        $report = $component->runChecks();
        $check = $this->findCheck($report, 'db');

        $this->assertSame(CheckResult::STATUS_OK, $check['status']);
    }

    public function testDbCheckFailure(): void
    {
        Yii::$app->set('db', ['class' => FailingDbConnection::class]);
        $component = new HealthComponent([
            'checks' => [
                'db' => [
                    'class' => DbCheck::class,
                    'componentId' => 'db',
                    'timeout' => 1,
                ],
            ],
        ]);

        $report = $component->runChecks();
        $check = $this->findCheck($report, 'db');

        $this->assertSame(CheckResult::STATUS_CRITICAL, $check['status']);
        $this->assertArrayHasKey('error', $check['meta']);
    }

    public function testHealthJsonStructure(): void
    {
        Yii::$app->set('db', ['class' => StubDbConnection::class]);
        $component = new HealthComponent([
            'checks' => [
                'db' => [
                    'class' => DbCheck::class,
                    'componentId' => 'db',
                    'timeout' => 1,
                ],
            ],
        ]);

        $report = $component->runChecks();

        $this->assertArrayHasKey('status', $report);
        $this->assertArrayHasKey('timestamp', $report);
        $this->assertArrayHasKey('duration', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertNotEmpty($report['checks']);

        $check = $this->findCheck($report, 'db');
        $this->assertArrayHasKey('id', $check);
        $this->assertArrayHasKey('status', $check);
        $this->assertArrayHasKey('meta', $check);
    }

    private function findCheck(array $report, string $id): array
    {
        foreach ($report['checks'] as $check) {
            if (($check['id'] ?? null) === $id) {
                return $check;
            }
        }

        return [];
    }

    private function mockApplication(): void
    {
        new Application([
            'id' => 'health-test',
            'basePath' => __DIR__,
            'components' => [],
        ]);
    }
}

class StubDbConnection extends Connection
{
    public $dsn = 'mysql:host=stub;dbname=test';

    public function open()
    {
        // pretend connection succeeds
    }

    public function close()
    {
    }

    public function createCommand($sql = null, $params = [])
    {
        return new class {
            public function execute()
            {
                return 1;
            }
        };
    }

    public function getSchema()
    {
        return new class {
            public function getServerVersion()
            {
                return 'stub';
            }
        };
    }
}

class FailingDbConnection extends Connection
{
    public function open()
    {
        throw new \RuntimeException('broken');
    }

    public function close()
    {
    }
}
