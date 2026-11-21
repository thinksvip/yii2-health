<?php

use PHPUnit\Framework\TestCase;
use Xincheng\Health\CheckResult;
use Xincheng\Health\HealthComponent;
use Xincheng\Health\checks\DbCheck;
use yii\console\Application;
use yii\db\Connection;
use yii\di\Container;

class HealthConfigurationTest extends TestCase
{
    private $sharedConnection;

    protected function setUp(): void
    {
        new Application(['id' => 'health-config-test', 'basePath' => __DIR__]);
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
        Yii::$container = new Container();
    }

    public function testDefaultChecksPreserveExplicitFlags(): void
    {
        $health = new HealthComponent(['autoDiscover' => false, 'autoRegisterRoutes' => false]);
        $this->assertArrayHasKey('db', $health->checks);
        $this->assertFalse($health->autoDiscover);
        $this->assertFalse($health->autoRegisterRoutes);
    }

    public function testPartialBuiltinConfigurationRetainsOnlySelectedChecks(): void
    {
        $health = new HealthComponent([
            'autoDiscover' => false,
            'checks' => ['db' => ['enabled' => false]],
        ]);

        $report = $health->runChecks();
        $this->assertSame(['db'], array_column($report['checks'], 'id'));
        $this->assertSame('Primary DB', $report['checks'][0]['name']);
        $this->assertSame('disabled', $report['checks'][0]['meta']['reason']);
    }

    public function testDatabaseCheckPreservesConfiguredPdoAttributes(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }
        Yii::$app->set('db', [
            '__class' => HealthAttributeConnection::class,
            'dsn' => 'sqlite::memory:',
            'attributes' => [PDO::ATTR_STRINGIFY_FETCHES => true],
        ]);

        $report = (new HealthComponent())->runChecksByIds(['db']);
        $this->assertSame(CheckResult::STATUS_OK, $report['status']);
        $this->assertSame(true, HealthAttributeConnection::$openedWith[PDO::ATTR_STRINGIFY_FETCHES] ?? null);
        $this->assertSame(3, HealthAttributeConnection::$openedWith[PDO::ATTR_TIMEOUT]);
        $this->assertFalse(Yii::$app->has('db', true));
    }

    public function testSharedDatabaseComponentsAreResolvedWithoutChangingTheHostConnection(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }
        $connection = new HealthAttributeConnection([
            'dsn' => 'sqlite::memory:',
            'attributes' => [PDO::ATTR_STRINGIFY_FETCHES => true],
        ]);
        $connection->open();
        $this->sharedConnection = $connection;
        Yii::$container->setSingleton(HealthAttributeConnection::class, $connection);
        $definitions = [
            $connection,
            static fn () => $connection,
            [$this, 'createDatabaseConnection'],
            HealthAttributeConnection::class,
        ];

        foreach ($definitions as $definition) {
            Yii::$app->set('db', $definition);
            $report = (new HealthComponent())->runChecksByIds(['db']);
            $this->assertSame(CheckResult::STATUS_OK, $report['status']);
            $this->assertSame(true, HealthAttributeConnection::$openedWith[PDO::ATTR_STRINGIFY_FETCHES] ?? null);
            $this->assertSame(3, HealthAttributeConnection::$openedWith[PDO::ATTR_TIMEOUT]);
            $this->assertTrue($connection->getIsActive());
            $this->assertSame([PDO::ATTR_STRINGIFY_FETCHES => true], $connection->attributes);
        }
    }

    public function testCustomResolverReceivesProbeOptionsAndKeepsAnOpenConnection(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }
        $connection = new Connection(['dsn' => 'sqlite::memory:']);
        $connection->open();
        $options = [];
        $health = new HealthComponent(['checks' => ['inventory' => [
            '__class' => DbCheck::class,
            'enabled' => true,
            'timeout' => 2,
            'componentResolver' => static function ($id, $overrides) use ($connection, &$options) {
                $options = $overrides;
                return $connection;
            },
        ]]]);

        $this->assertSame(CheckResult::STATUS_OK, $health->runChecks()['status']);
        $this->assertSame(2, $options['attributes'][PDO::ATTR_TIMEOUT]);
        $this->assertTrue($connection->getIsActive());
        $this->assertNull($connection->attributes);
    }

    public function testUncloneableServiceReportsIsolationFailure(): void
    {
        $service = new class {
            private function __clone() {}
        };
        Yii::$app->set('db', $service);

        $report = (new HealthComponent())->runChecksByIds(['db']);
        $this->assertSame(CheckResult::STATUS_CRITICAL, $report['status']);
        $this->assertSame('Cannot isolate component "db" for a health check', $report['checks'][0]['meta']['error']);
        $this->assertSame($service, Yii::$app->get('db'));
    }

    public function testDiscoveryRecognizesLoadedFactoriesWithoutInvokingOtherFactories(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }
        Yii::$app->set('inventory', static fn () => new Connection(['dsn' => 'sqlite::memory:']));
        Yii::$app->get('inventory');
        Yii::$app->set('unused', static function () {
            throw new LogicException('Unrelated component was initialized');
        });

        $report = (new HealthComponent())->runChecksByIds(['inventory']);
        $this->assertSame(['inventory'], array_column($report['checks'], 'id'));
        $this->assertSame(CheckResult::STATUS_OK, $report['status']);
        $this->assertFalse(Yii::$app->has('unused', true));
    }

    public function testProbeUsesTheCurrentConfigurationOfALoadedDatabase(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required');
        }
        Yii::$app->set('db', ['class' => Connection::class, 'dsn' => 'missingdriver:initial']);
        $db = Yii::$app->get('db');
        $db->dsn = 'sqlite::memory:';
        $db->open();

        $report = (new HealthComponent())->runChecksByIds(['db']);
        $this->assertSame(CheckResult::STATUS_OK, $report['status']);
        $this->assertSame('sqlite::memory:', $report['checks'][0]['meta']['dsn']);
        $this->assertTrue($db->getIsActive());
    }

    public function createDatabaseConnection(): Connection
    {
        return $this->sharedConnection;
    }
}

class HealthAttributeConnection extends Connection
{
    public static $openedWith;

    public function open()
    {
        self::$openedWith = $this->attributes;
        parent::open();
    }
}
