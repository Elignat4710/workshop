<?php

namespace App\Jobs;

use App\Services\Agent\Jobs\ProcessBrowserReply;
use App\Services\Agent\Jobs\ProcessCookieReply;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

class RabbitMqJob extends BaseJob
{
    protected ?string $jobUuid = null;

    public function uuid(): string
    {
        if ($this->jobUuid === null) {
            $this->jobUuid = (string) Str::uuid();
        }

        return $this->jobUuid;
    }

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

    public function getName(): string
    {
        return 'RabbitMQJob';
    }

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
}
