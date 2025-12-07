Prompt:
Объясни, как работает @app/Jobs/RabbitMqJob.php? Какая логика обработки сообщений ?

Ответ:

Изучаю связанные классы для контекста:


[5 tools called]


Как работает `RabbitMqJob` и логика обработки сообщений:

## Обзор класса

`RabbitMqJob` расширяет базовый класс `RabbitMQJob` из библиотеки `laravel-queue-rabbitmq` и обрабатывает сообщения из RabbitMQ в Laravel Queue.

## Основные компоненты

### 1. Генерация UUID задачи

```15:22:app/Jobs/RabbitMqJob.php
public function uuid(): string
{
    if ($this->jobUuid === null) {
        $this->jobUuid = (string) Str::uuid();
    }

    return $this->jobUuid;
}
```

Генерирует уникальный UUID для каждой задачи, если он еще не создан.

### 2. Модификация тела сообщения

```24:35:app/Jobs/RabbitMqJob.php
public function getRawBody(): string
{
    $body = parent::getRawBody();

    $data = json_decode($body, true);
    if (is_array($data)) {
        $data['uuid'] = $this->uuid();
        return json_encode($data);
    }

    return $body;
}
```

Добавляет UUID в JSON-тело сообщения перед обработкой.

### 3. Формирование payload для Laravel Queue

```42:58:app/Jobs/RabbitMqJob.php
public function payload()
{
    $body = $this->getRawBody();

    $decoded = json_decode($body, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        $data = $decoded;
    } else {
        $data = ['raw_body' => $body];
    }

    return [
        'job' => self::class,
        'data' => $data,
    ];
}
```

Формирует стандартный payload для Laravel Queue с указанием класса джоба и данными.

## Основная логика обработки (`fire()`)

```60:116:app/Jobs/RabbitMqJob.php
public function fire(): void
{
    try {
        $rawBody = parent::getRawBody();

        $data = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('RabbitMQJob: Invalid JSON', [
                'error' => json_last_error_msg(),
                'raw_body' => $rawBody
            ]);
            $this->delete();
            return;
        }

        $correlationId = $this->getRabbitMQMessage()->get_properties()['correlation_id'] ?? null;
        $data['correlation_id'] = $correlationId;

        Log::info('RabbitMQJob: received message', [
            'correlation_id' => $correlationId,
            'queue' => $this->getQueue()
        ]);

        $queue = $this->getQueue();

        if ($queue === config('rabbitmq_queue_name.agent_result')) {
            if (in_array($data['type'], config('rabbitmq_queue_name.browser_tasks_types'))) {
                $job = $this->resolve(ProcessBrowserReply::class);
                $job->handle($data);
            } elseif ($data['type'] === config('scenario.cookie')) {
                $job = $this->resolve(ProcessCookieReply::class);
                $job->handle($data);
            } else {
                Log::warning('RabbitMQJob: Unknown task type', [
                    'type' => $data['type']
                ]);
            }

            Log::info('RabbitMQJob: Job processed successfully');
        } else {
            Log::warning('RabbitMQJob: Unknown queue', [
                'queue' => $queue
            ]);
        }

        $this->delete();

    } catch (\Throwable $e) {
        Log::error('RabbitMQJob: Error processing', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        $this->fail($e);
        $this->delete();
    }
}
```

### Алгоритм обработки:

1. Парсинг JSON: извлекает тело сообщения и парсит JSON. При ошибке логирует и удаляет задачу.
2. Извлечение correlation_id: берет `correlation_id` из свойств RabbitMQ-сообщения и добавляет в данные.
3. Определение очереди: проверяет, что очередь — `agent_result` (по умолчанию `ads_power_agent_result`).
4. Маршрутизация по типу задачи:
   - Если тип в `browser_tasks_types` (например, `browser.reddit_upvote_flow`, `browser.reddit_tree_dot_flow` и т.д.) → вызывает `ProcessBrowserReply`.
   - Если тип = `browser.cookie_robot` → вызывает `ProcessCookieReply`.
   - Иначе логирует предупреждение о неизвестном типе.
5. Завершение: после успешной обработки удаляет задачу из очереди.
6. Обработка ошибок: при исключении логирует, помечает задачу как failed и удаляет.

## Типы обрабатываемых задач

### Browser задачи (`ProcessBrowserReply`):
- `browser.reddit_upvote_flow`
- `browser.reddit_tree_dot_flow`
- `browser.copy_post_or_comment_flow`
- `browser.subscription_for_sub_flow`
- `browser.open_notification_flow`

### Cookie задачи (`ProcessCookieReply`):
- `browser.cookie_robot`

## Связь с системой

- `correlation_id` связывает ответ с исходной задачей в `TaskManager`.
- Обработчики обновляют статусы задач и профилей в БД через репозитории.
- Используется транзакционная обработка для целостности данных.

Этот класс служит маршрутизатором между RabbitMQ и бизнес-логикой приложения, обрабатывая ответы от внешних агентов (например, AdsPower).
