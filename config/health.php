<?php

use Xincheng\Health\HealthComponent;
use Xincheng\Health\checks\DbCheck;
use Xincheng\Health\checks\RedisCheck;
use Xincheng\Health\checks\QueueCheck;
use Xincheng\Health\checks\MongoDbCheck;
use Xincheng\Health\checks\ElasticsearchCheck;
use Xincheng\Health\checks\RabbitMqCheck;
use Xincheng\Health\checks\TdengineCheck;
use Xincheng\Health\checks\KafkaCheck;

return [
    'class' => HealthComponent::class,
    'autoDiscover' => true,
    'autoRegisterRoutes' => true,
    'checks' => [
        'db' => [
            'class' => DbCheck::class,
            'name' => 'Primary DB',
            'componentId' => 'db',
            'enabled' => 'auto',
            'timeout' => 3,
        ],
        'redis' => [
            'class' => RedisCheck::class,
            'name' => 'Redis',
            'componentId' => 'redis',
            'enabled' => 'auto',
            'timeout' => 2,
        ],
        'queue' => [
            'class' => QueueCheck::class,
            'name' => 'Queue',
            'componentId' => 'queue',
            'enabled' => 'auto',
            'timeout' => 3,
        ],
        'mongodb' => [
            'class' => MongoDbCheck::class,
            'name' => 'MongoDB',
            'componentId' => 'mongodb',
            'enabled' => 'auto',
            'timeout' => 3,
        ],
        'elasticsearch' => [
            'class' => ElasticsearchCheck::class,
            'name' => 'Elasticsearch',
            'componentId' => 'elasticsearch',
            'enabled' => 'auto',
            'timeout' => 3,
        ],
        'rabbitmq' => [
            'class' => RabbitMqCheck::class,
            'name' => 'RabbitMQ',
            'componentId' => 'rabbitmq',
            'enabled' => 'auto',
            'timeout' => 3,
        ],
        'tdengine' => [
            'class' => TdengineCheck::class,
            'name' => 'TDengine',
            'componentId' => 'tdengine',
            'enabled' => 'auto',
            'timeout' => 3,
        ],
        'kafka' => [
            'class' => KafkaCheck::class,
            'name' => 'Kafka',
            'componentId' => 'kafka',
            'enabled' => 'auto',
            'timeout' => 3,
        ],
    ],
];
