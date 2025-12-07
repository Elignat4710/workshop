Prompt:

Нужно создать новый сервис Notification по аналогии с сущетсвующими сервисами. Суть этого сервиса - отправлять уведомления пользователям (владельцам ботов) в телеграм, когда бот закончит свою обработку. Тригерить нужно на статус completed или failed

Ответ:

Изучаю структуру существующих сервисов и логику работы ботов, чтобы понять, как реализовать сервис Notification для отправки уведомлений в Telegram.


[3 tools called]


Изучаю структуру существующих сервисов и модели ботов:


[6 tools called]


Изучаю примеры контрактов и менеджеров, а также обработку ответов от агентов:


[6 tools called]


Проверяю миграции и конфигурацию для Telegram:


[4 tools called]


Проверяю миграции пользователей и структуру базы данных:


[3 tools called]


Создаю структуру сервиса Notification по аналогии с существующими сервисами. Ниже файлы для создания:

## 1. Контракт для отправки уведомлений

```php:app/Services/Notification/Contracts/SendNotificationInterface.php
<?php

namespace App\Services\Notification\Contracts;

use App\Services\TaskManager\Models\TaskManager;

interface SendNotificationInterface
{
    public function sendBotCompletionNotification(TaskManager $taskManager, string $status): void;
}
```

## 2. Менеджер для отправки уведомлений

```php:app/Services/Notification/Managers/SendNotificationManager.php
<?php

namespace App\Services\Notification\Managers;

use App\Services\Notification\Contracts\SendNotificationInterface;
use App\Services\TaskManager\Models\TaskManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendNotificationManager implements SendNotificationInterface
{
    public function sendBotCompletionNotification(TaskManager $taskManager, string $status): void
    {
        try {
            $profileBot = $taskManager->profileBot;
            $user = $profileBot->user;

            if (!$user || !$user->telegram_chat_id) {
                Log::info('SendNotificationManager: User has no telegram_chat_id', [
                    'user_id' => $user?->id,
                    'profile_bot_id' => $profileBot->id
                ]);
                return;
            }

            $botToken = config('services.telegram.bot_token');
            if (!$botToken) {
                Log::warning('SendNotificationManager: Telegram bot token not configured');
                return;
            }

            $message = $this->buildMessage($profileBot, $taskManager, $status);
            $telegramApiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

            $response = Http::post($telegramApiUrl, [
                'chat_id' => $user->telegram_chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);

            if ($response->successful()) {
                Log::info('SendNotificationManager: Notification sent successfully', [
                    'user_id' => $user->id,
                    'profile_bot_id' => $profileBot->id,
                    'status' => $status
                ]);
            } else {
                Log::error('SendNotificationManager: Failed to send notification', [
                    'user_id' => $user->id,
                    'response' => $response->body()
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SendNotificationManager: Error sending notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function buildMessage($profileBot, TaskManager $taskManager, string $status): string
    {
        $statusEmoji = $status === 'completed' ? '✅' : '❌';
        $statusText = $status === 'completed' ? 'завершена' : 'завершена с ошибкой';
        
        $scenarioName = $taskManager->scenario->name ?? 'Неизвестный сценарий';
        $botName = $profileBot->adsPower->profile_name ?? "Бот #{$profileBot->id}";
        
        $message = "<b>{$statusEmoji} Обработка бота {$statusText}</b>\n\n";
        $message .= "<b>Бот:</b> {$botName}\n";
        $message .= "<b>Сценарий:</b> {$scenarioName}\n";
        $message .= "<b>Статус:</b> " . ucfirst($status) . "\n";
        
        if ($status === 'failed' && $taskManager->error_message) {
            $message .= "\n<b>Ошибка:</b> {$taskManager->error_message}";
        }
        
        return $message;
    }
}
```

## 3. Service Provider

```php:app/Services/Notification/NotificationServiceProvider.php
<?php

namespace App\Services\Notification;

use App\Services\Notification\Contracts\SendNotificationInterface;
use App\Services\Notification\Managers\SendNotificationManager;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SendNotificationInterface::class, SendNotificationManager::class);
    }
}
```

## 4. Обновление ProcessBrowserReply для отправки уведомлений

```php:app/Services/Agent/Jobs/ProcessBrowserReply.php
<?php

namespace App\Services\Agent\Jobs;

use App\Services\Notification\Contracts\SendNotificationInterface;
use App\Services\ProfileBot\Contracts\ProfileBotRepoInterface;
use App\Services\Status\Enums\RedditRegStatus;
use App\Services\Status\Enums\TaskStatus;
use App\Services\TaskManager\Contracts\TaskManagerRepoInterface;
use Illuminate\Support\Facades\DB;

class ProcessBrowserReply
{
    public function __construct(
        private TaskManagerRepoInterface $taskManagerRepo,
        private ProfileBotRepoInterface $profileBotRepo,
        private SendNotificationInterface $sendNotification
    ) {
    }

    public function handle(array $data): void
    {
        $correlationId = $data['correlation_id'];

        $taskManager = $this->taskManagerRepo->findByCorrelationId($correlationId);
        $profileBot = $this->profileBotRepo->find($taskManager->profile_bot_id);
        $statusEnum = TaskStatus::fromString($data['status']);
        $errorMessage = $data['message'] ?? 'unknown error';

        DB::transaction(function () use ($statusEnum, $taskManager, $profileBot, $errorMessage) {
            $this->taskManagerRepo->update(['status_id' => $statusEnum->value], $taskManager->id);

            if ($statusEnum === TaskStatus::COMPLETED) {
                $this->profileBotRepo->update([
                    'reddit_reg_status_id' => RedditRegStatus::COMPLETED->value
                ], $profileBot->id);
                
                // Отправка уведомления о завершении
                $this->sendNotification->sendBotCompletionNotification($taskManager, 'completed');
            }

            if ($statusEnum === TaskStatus::FAILED) {
                if ($profileBot->reddit_reg_status_id === RedditRegStatus::COMPLETED->value) {
                    return;
                }
                $this->taskManagerRepo->update(['error_message' => $errorMessage], $taskManager->id);
                $this->profileBotRepo->update([
                    'reddit_reg_status_id' => RedditRegStatus::FAILED->value
                ], $profileBot->id);
                
                // Отправка уведомления об ошибке
                $this->sendNotification->sendBotCompletionNotification($taskManager, 'failed');
            }
        });
    }
}
```

## 5. Обновление ProcessCookieReply для отправки уведомлений

```php:app/Services/Agent/Jobs/ProcessCookieReply.php
<?php

namespace App\Services\Agent\Jobs;

use App\Services\AdsPower\Contracts\AdsPowerRepoInterface;
use App\Services\Notification\Contracts\SendNotificationInterface;
use App\Services\ProfileBot\Contracts\ProfileBotRepoInterface;
use App\Services\Status\Enums\CookieStepStatus;
use App\Services\Status\Enums\TaskStatus;
use App\Services\TaskManager\Contracts\TaskManagerRepoInterface;
use Illuminate\Support\Facades\DB;

class ProcessCookieReply
{
    public function __construct(
        private TaskManagerRepoInterface $taskManagerRepo,
        private ProfileBotRepoInterface $profileBotRepo,
        private AdsPowerRepoInterface $adsPowerRepo,
        private SendNotificationInterface $sendNotification
    ) {
    }

    public function handle(array $data): void
    {
        $correlationId = $data['correlation_id'];
        $taskManager = $this->taskManagerRepo->findByCorrelationId($correlationId);
        $statusEnum = TaskStatus::fromString($data['status']);
        $profileBot = $this->profileBotRepo->infoForScenario($taskManager->profile_bot_id);

        DB::transaction(function () use ($taskManager, $statusEnum, $profileBot, $data) {
            $this->taskManagerRepo->update(['status_id' => $statusEnum->value], $taskManager->id);

            if ($statusEnum === TaskStatus::COMPLETED) {
                $profileConfig = $data['profile_config'] ?? [];
                if (isset($profileConfig['status']) && $profileConfig['status'] === 'error') {
                    $config = $profileConfig['message'];
                    \Log::info('ProcessCookieReply: config', [
                        'config' => $config
                    ]);
                    $cookieStepStatus = CookieStepStatus::FAILED;
                } else {
                    $config = $profileConfig['data']['list'][0] ?? [];
                    $cookieStepStatus = CookieStepStatus::COMPLETED;
                }

                $this->profileBotRepo->update([
                    'cookie_status_id' => $cookieStepStatus->value
                ], $profileBot->id);
                $this->adsPowerRepo->update([
                    'config' => $config
                ], $profileBot->ads_power_id);
                
                // Отправка уведомления о завершении
                $this->sendNotification->sendBotCompletionNotification($taskManager, 'completed');
            }
            if ($statusEnum === TaskStatus::FAILED) {
                $this->profileBotRepo->update([
                    'cookie_status_id' => CookieStepStatus::FAILED->value
                ], $profileBot->id);
                
                // Отправка уведомления об ошибке
                $this->sendNotification->sendBotCompletionNotification($taskManager, 'failed');
            }
        });
    }
}
```

## 6. Регистрация Service Provider

Добавьте в `bootstrap/providers.php`:

```php:bootstrap/providers.php
// ... existing code ...
    App\Services\Status\StatusServiceProvider::class,
    App\Services\Proxy\ProxyServiceProvider::class,
    App\Services\Notification\NotificationServiceProvider::class,
];
```

## 7. Конфигурация Telegram в config/services.php

Добавьте в `config/services.php`:

```php:config/services.php
// ... existing code ...
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    ],

];
```

## 8. Обновление модели User

Добавьте поле `telegram_chat_id` в `app/Models/User.php`:

```php:app/Models/User.php
// ... existing code ...
    protected $fillable = [
        'name',
        'email',
        'password',
        'telegram_chat_id',
    ];
// ... existing code ...
```

## 9. Миграция для добавления поля telegram_chat_id

Создайте миграцию:

```bash
php artisan make:migration add_telegram_chat_id_to_users_table
```

И содержимое миграции:

```php:database/migrations/YYYY_MM_DD_HHMMSS_add_telegram_chat_id_to_users_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
```

## Резюме

Создан сервис Notification, который:
- Отправляет уведомления в Telegram при статусах `completed` и `failed`
- Интегрирован в `ProcessBrowserReply` и `ProcessCookieReply`
- Использует структуру, аналогичную другим сервисам проекта
- Логирует ошибки и пропускает пользователей без `telegram_chat_id`

Для работы добавьте в `.env`:
```
TELEGRAM_BOT_TOKEN=your_bot_token_here
```

И выполните миграцию для добавления поля `telegram_chat_id` в таблицу `users`.