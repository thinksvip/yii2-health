<?php

use PhpAmqpLib\Connection\AMQPLazyConnection;
use PHPUnit\Framework\TestCase;
use Xincheng\Health\CheckResult;
use Xincheng\Health\checks\BaseCheck;
use Xincheng\Health\checks\ElasticsearchCheck;
use Xincheng\Health\checks\MongoDbCheck;
use Xincheng\Health\checks\RabbitMqCheck;
use Xincheng\Health\checks\RedisCheck;

class MiddlewareCheckTest extends TestCase
{
    public function testPersistentRedisProbeRetainsInjectedAndPrivateConfiguration(): void
    {
        if (!class_exists(\yii\redis\Connection::class)) {
            $this->markTestSkipped('Requires the optional Yii Redis driver.');
        }
        $dependency = new \stdClass();
        $connection = new class($dependency) extends \yii\redis\Connection {
            private $dependency;
            private $configured;

            public function __construct(\stdClass $dependency, array $config = [])
            {
                $this->dependency = $dependency;
                parent::__construct($config);
            }

            public function setProbeDependency($dependency)
            {
                $this->configured = $dependency;
            }

            public function open(): void
            {
                if ($this->configured !== $this->dependency || (($this->socketClientFlags ?? 0) & STREAM_CLIENT_PERSISTENT)) {
                    throw new \LogicException('Redis probe configuration mismatch.');
                }
            }

            public function executeCommand($name, $params = [])
            {
                return $name === 'PING' ? 'PONG' : false;
            }
        };
        $connection->setProbeDependency($dependency);
        if (property_exists($connection, 'socketClientFlags')) {
            $connection->socketClientFlags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT;
        }
        $check = new RedisCheck();
        $this->configureCheck($check, $connection, 'redis');
        $result = $check->run();
        $this->assertSame(CheckResult::STATUS_OK, $result->status);
        if (property_exists($connection, 'socketClientFlags')) {
            $this->assertSame(STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $connection->socketClientFlags);
        }
    }

    public function testElasticsearchUsesDecodedHealthAndRejectsMissingStatus(): void
    {
        if (!class_exists(\yii\elasticsearch\Connection::class)) {
            $this->markTestSkipped('Requires the optional Yii Elasticsearch driver.');
        }
        $connection = new class extends \yii\elasticsearch\Connection {
            public $response;

            public function get($url, $options = [], $body = null, $raw = false)
            {
                if ($url !== '_cluster/health') {
                    throw new \LogicException('Unexpected Elasticsearch request');
                }
                return $raw ? json_encode($this->response) : $this->response;
            }
        };
        $check = new ElasticsearchCheck();
        $this->configureCheck($check, $connection, 'elasticsearch');

        foreach ([
            [['status' => 'green'], CheckResult::STATUS_OK, 'green'],
            [['status' => 'yellow'], CheckResult::STATUS_WARNING, 'yellow'],
            [['status' => 'red'], CheckResult::STATUS_CRITICAL, 'red'],
            [false, CheckResult::STATUS_CRITICAL, 'unknown'], // the driver's HTTP 404 result
            [[], CheckResult::STATUS_CRITICAL, 'unknown'],
        ] as [$response, $status, $clusterStatus]) {
            $connection->response = $response;
            $result = $check->run();
            $this->assertSame($status, $result->status);
            $this->assertSame($clusterStatus, $result->meta['clusterStatus']);
        }
    }

    public function testMongoPingUsesAdminWhenUriHasNoDatabaseAndLimitsDriverWaits(): void
    {
        if (!class_exists(\yii\mongodb\Connection::class) || !class_exists(\MongoDB\Driver\Command::class)) {
            $this->markTestSkipped('Requires the optional Yii MongoDB driver and ext-mongodb.');
        }
        $manager = new class {
            public $ping;

            public function executeCommand($database, $command)
            {
                $this->ping = [$database, $command];
            }
        };
        $connection = new \yii\mongodb\Connection([
            'dsn' => 'mongodb://localhost',
            'manager' => $manager,
            'options' => ['connectTimeoutMS' => 100, 'socketTimeoutMS' => 30000],
        ]);
        $check = new MongoDbCheck();
        $check->timeout = 0.25;
        $this->configureCheck($check, $connection, 'mongodb');

        foreach (['admin', 'orders'] as $database) {
            if ($database === 'orders') {
                $connection->setDefaultDatabaseName($database);
            }
            $result = $check->run();
            $this->assertSame(CheckResult::STATUS_OK, $result->status);
            $this->assertSame($database, $result->meta['database']);
            $this->assertSame($database, $manager->ping[0]);
            $this->assertInstanceOf(\MongoDB\Driver\Command::class, $manager->ping[1]);
        }
        $this->assertSame(100, $connection->options['connectTimeoutMS']);
        $this->assertSame(250, $connection->options['socketTimeoutMS']);
        $this->assertSame(250, $connection->options['serverSelectionTimeoutMS']);
    }

    public function testRabbitMqBoundsChannelWaitOnLazyConnection(): void
    {
        if (!class_exists(AMQPLazyConnection::class)) {
            $this->markTestSkipped('Requires the optional php-amqplib driver.');
        }
        $check = new class extends RabbitMqCheck {
            public function channelWait(array $config)
            {
                return $this->openConnection($config)->rpcTimeout;
            }
        };
        $check->timeout = 0.25;
        $config = ['type' => RecordingLazyConnection::class, 'read_write_timeout' => 720];
        $this->assertSame(0.25, $check->channelWait($config));
        $this->assertSame(0.25, $check->channelWait($config + ['channel_rpc_timeout' => 3]));
        $this->assertSame(0.1, $check->channelWait($config + ['channel_rpc_timeout' => 0.1]));
    }

    private function configureCheck(BaseCheck $check, $connection, string $id): void
    {
        $check->id = $id;
        $check->enabled = true;
        $check->componentResolver = static function () use ($connection) {
            return $connection;
        };
    }
}

if (class_exists(AMQPLazyConnection::class)) {
    class RecordingLazyConnection extends AMQPLazyConnection
    {
        public $rpcTimeout;

        public function __construct(...$args)
        {
            parent::__construct(...$args);
            $this->rpcTimeout = $args[14];
        }
    }
}
