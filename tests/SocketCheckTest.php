<?php

use PHPUnit\Framework\TestCase;
use Xincheng\Health\CheckResult;
use Xincheng\Health\HealthComponent;
use yii\console\Application;

class SocketCheckTest extends TestCase
{
    protected function setUp(): void
    {
        new Application(['id' => 'socket-check-test', 'basePath' => __DIR__, 'extensions' => []]);
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
    }

    public function testKafkaTriesOtherBootstrapBrokersAndUsesPublicGetter(): void
    {
        if (class_exists(\RdKafka\Producer::class)) {
            $this->markTestSkipped('This test exercises the TCP fallback without ext-rdkafka.');
        }
        $server = $this->listen();
        $other = $this->listen();
        $unavailable = stream_socket_get_name($other, false);
        fclose($other);
        try {
            $brokers = [$unavailable, stream_socket_get_name($server, false)];
            $component = new class($brokers) {
                private $brokers;

                public function __construct(array $brokers)
                {
                    $this->brokers = $brokers;
                }

                public function getBootstrapServers(): array
                {
                    return $this->brokers;
                }
            };
            Yii::$app->set('kafka', $component);
            $report = (new HealthComponent())->runChecksByIds(['kafka']);
            $this->assertSame(CheckResult::STATUS_OK, $report['status']);
            $this->assertSame($brokers, $report['checks'][0]['meta']['brokers']);
        } finally {
            fclose($server);
        }
        $this->assertSame(CheckResult::STATUS_CRITICAL, (new HealthComponent())->runChecksByIds(['kafka'])['status']);
    }

    public function testKafkaAcceptsBracketedIpv6Endpoint(): void
    {
        if (class_exists(\RdKafka\Producer::class)) {
            $this->markTestSkipped('This test exercises the TCP fallback without ext-rdkafka.');
        }
        $server = $this->listen(true);
        try {
            Yii::$app->params['kafkaBrokers'] = stream_socket_get_name($server, false);
            $this->assertSame(CheckResult::STATUS_OK, (new HealthComponent())->runChecksByIds(['kafka'])['status']);
        } finally {
            fclose($server);
        }
    }

    public function testTdengineUsesDsnWhenOptionalHostAndPortAreUnavailable(): void
    {
        $server = $this->listen();
        $endpoint = stream_socket_get_name($server, false);
        $dsn = 'taos://' . $endpoint . '/example';
        try {
            $withPrivateFields = new class {
                private $host;
                private $port;
                public $dsn;
            };
            $withPrivateFields->dsn = $dsn;
            foreach ([(object)['host' => null, 'port' => null, 'dsn' => $dsn], $withPrivateFields] as $component) {
                Yii::$app->set('tdengine', $component);
                $report = (new HealthComponent())->runChecksByIds(['tdengine']);
                $this->assertSame(CheckResult::STATUS_OK, $report['status']);
                $this->assertSame('127.0.0.1', $report['checks'][0]['meta']['host']);
            }
        } finally {
            fclose($server);
        }
        $this->assertSame(CheckResult::STATUS_CRITICAL, (new HealthComponent())->runChecksByIds(['tdengine'])['status']);
    }

    private function listen(bool $ipv6 = false)
    {
        $server = false;
        if (function_exists('stream_socket_server')) {
            $server = @stream_socket_server($ipv6 ? 'tcp://[::1]:0' : 'tcp://127.0.0.1:0');
        } elseif (function_exists('socket_export_stream')) {
            $socket = @socket_create($ipv6 ? AF_INET6 : AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket !== false) {
                if (@socket_bind($socket, $ipv6 ? '::1' : '127.0.0.1', 0) && socket_listen($socket, 4)) {
                    $server = socket_export_stream($socket);
                }
                if (!$server) {
                    socket_close($socket);
                }
            }
        }
        if (!$server) {
            $this->markTestSkipped('A listening loopback socket is required.');
        }
        return $server;
    }
}
