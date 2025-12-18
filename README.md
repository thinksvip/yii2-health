# xincheng/yii2-health

面向 Kubernetes 的 Yii2 健康检查扩展（支持 `liveness/readiness` 与 CLI 探针），可自动探测项目中已配置的中间件并输出统一的健康报告。

## 功能特性

- **即插即用**：通过 `yiisoft/yii2-composer` 自动 bootstrap，无需手动改动路由规则。
- **两种探针模型**：
  - **HTTP 探针**（Web / php-fpm 模型）：`/livez`、`/readyz`、`/healthz`。
  - **CLI 探针**（纯 worker/脚本模型）：`php yii health/run`。
- **可选依赖友好**：默认 `enabled=auto`，未配置的组件会返回 `skipped`，不会 fatal。
- **多 DB 场景**：支持多个 `yii\db\Connection` 并存，配合 `autoDiscover` 自动发现/逐个检测。
- **已内置 checks**：MySQL/Redis/MongoDB/Elasticsearch/RabbitMQ/Queue/TDengine/Kafka。

## 环境要求

- PHP `>= 7.4`
- Yii2 `~2.0.49`

## 安装

### 方式一：Composer 安装（推荐）

在项目根目录执行：

```bash
composer require xincheng/yii2-health
composer dump-autoload
```

> 说明：如果你是以本仓库的本地路径方式接入（例如把包放在 `vendor/xincheng/yii2-health`），请先在主项目 `composer.json` 配置 `path` 仓库，再执行 `composer require xincheng/yii2-health`。

示例（主项目 `composer.json`）：

```json
{
  "repositories": [
    { "type": "path", "url": "vendor/xincheng/yii2-health", "options": { "symlink": true } }
  ]
}
```

## 使用

### CLI（适合 worker / cron / 脚本容器）

```bash
php yii health/run --format=json
php yii health/run --format=text
```

退出码：
- overall `critical`：返回非 0（用于 k8s `exec` readinessProbe）
- 其它状态：返回 0

### HTTP（适合 Web / php-fpm 模型）

业务接口：
- `GET /health.json`：JSON 明细（HTTP 200/503）
- `GET /health/summary`：只返回 `ok|warning|critical|skipped`（HTTP 200/503）
- `GET /health`：HTML 面板（HTTP 200/503）

Kubernetes 探针接口：
- `GET /livez`：liveness（永远 200，返回 `ok`，不做外部依赖检查）
- `GET /readyz`：readiness（200/503，返回 `ready`/`not-ready`）
  - 默认 `full=0`：不跑 `autoDiscover` 的检查（避免多 DB/慢依赖拖慢探针）
  - 可选：`/readyz?full=1` 包含 `autoDiscover`；`/readyz?checks=db,redis` 只跑指定 checks
- `GET /healthz`：k8s 风格 JSON（默认精简；`verbose=1`/失败时带 `checks[]`）
  - 示例：`/healthz?verbose=1&full=1`

## Kubernetes 配置示例

### 模型一：纯 CLI / Worker（无 Nginx、无 HTTP 入口）

推荐用 **exec probe**：

```yaml
readinessProbe:
  exec:
    command: ["sh", "-lc", "php yii health/run --format=json > /dev/null"]
  initialDelaySeconds: 10
  periodSeconds: 15
  timeoutSeconds: 10
  failureThreshold: 3

# livenessProbe 不建议做外部依赖检查（避免依赖抖动导致重启风暴）
livenessProbe:
  exec:
    command: ["sh", "-lc", "php -r \"echo 'ok';\" > /dev/null"]
  initialDelaySeconds: 30
  periodSeconds: 30
  timeoutSeconds: 3
  failureThreshold: 3
```

### 模型二：Web / PHP-FPM（有 HTTP 服务）

推荐用 **httpGet probe**：

```yaml
ports:
  - name: http
    containerPort: 80

livenessProbe:
  httpGet:
    path: /livez
    port: http
  initialDelaySeconds: 30
  periodSeconds: 30
  timeoutSeconds: 3
  failureThreshold: 3

readinessProbe:
  httpGet:
    path: /readyz
    port: http
  initialDelaySeconds: 10
  periodSeconds: 15
  timeoutSeconds: 10
  failureThreshold: 3
```

补充说明：
- 如果你的容器 **只有 php-fpm:9000**（没有 nginx/http server），k8s 的 `httpGet` probe 无法直接用，请改用上面的 **exec probe**。
- 如果 Pod 里有 **nginx 容器 + php-fpm 容器** 两个容器：通常把 `httpGet` probe 配在 nginx 容器上；php-fpm 容器可选用 exec probe 做兜底。

## 配置

默认配置见 `config/health.php`，也可以在应用配置中覆盖（示例）：

```php
'components' => [
    'health' => [
        'class' => \Xincheng\Health\HealthComponent::class,
        'autoDiscover' => false, // worker/readiness 建议关闭
        'checks' => [
            'db' => [
                'enabled' => false, // 显式关闭：请使用 bool，避免字符串歧义
            ],
            'kafka' => [
                'enabled' => 'auto',
                'brokers' => 'kafka-1:9092,kafka-2:9092',
            ],
        ],
    ],
],
```

配置来源（Kafka / TDengine）：

- 扩展本身不会读取任何业务环境变量（避免绑定到某个项目的 env 前缀）。
- Kafka / TDengine 的检查会优先从 **业务项目已有配置**中自动取值（不需要在 `health` 里重复配置），按以下顺序解析：
  1. check 自身参数（例如你显式覆写了 `health.checks.kafka.brokers`）
  2. `Yii::$app->kafka` / `Yii::$app->tdengine` 组件（常见做法）
  3. `Yii::$app->params['kafka']` / `Yii::$app->params['tdengine']`（适合统一由 params 管理配置）

示例 A：业务项目配置了 `kafka/tdengine` 组件，health 自动复用（推荐）

```php
'components' => [
    'kafka' => [
        'class' => \app\components\KafkaClient::class,
        // health 会识别 brokers / bootstrapServers 字段（属性或 getter）
        'brokers' => getenv('KAFKA_BROKERS') ?: null, // host:port,host:port
    ],
    'tdengine' => [
        'class' => \app\components\TdengineClient::class,
        // health 会识别 host+port 或 dsn
        'host' => getenv('TDENGINE_HOST') ?: null,
        'port' => getenv('TDENGINE_PORT') ?: null,
    ],
],
```

示例 B：业务项目使用 `params` 管理配置，health 自动复用

```php
'params' => [
    'kafka' => [
        'brokers' => getenv('KAFKA_BROKERS') ?: null,
    ],
    'tdengine' => [
        'host' => getenv('TDENGINE_HOST') ?: null,
        'port' => getenv('TDENGINE_PORT') ?: null,
        // 或 'dsn' => 'taos://host:6041/db',
    ],
],
```

## 测试

```bash
./vendor/bin/phpunit -c vendor/xincheng/yii2-health/phpunit.xml.dist
```

## License

MIT
