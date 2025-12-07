Use Case #3

Prompt:
Создай новый класс ProcessEmailVerificationReply по аналогии с ProcessBrowserReply. 
Он должен обновлять поле email_verification_status_id в ProfileBot модели 
при получении результата верификации email.

Ответ:
Изучаю структуру `ProcessBrowserReply` и модели `ProfileBot` для создания аналогичного класса `ProcessEmailVerificationReply`.


[4 tools called]


Проверяю наличие поля `email_verification_status_id` в модели и ищу enum для статусов верификации email:


[3 tools called]


Проверяю использование `ProcessBrowserReply` в `RabbitMqJob` и ищу enum для статусов верификации email:


[2 tools called]


Изучаю `ProcessCookieReply` для дополнительного контекста:


[2 tools called]


Создаю класс `ProcessEmailVerificationReply` по аналогии с `ProcessBrowserReply`. Сначала создам enum `EmailVerificationStatus` (аналогично `RedditRegStatus`), затем сам класс.

Вот код для обоих файлов:

## 1. Enum для статусов верификации email

Создайте файл `app/Services/Status/Enums/EmailVerificationStatus.php`:

```php
<?php

namespace App\Services\Status\Enums;

enum EmailVerificationStatus: int
{
    case PENDING = 1;
    case COMPLETED = 3;
    case FAILED = 4;

    public static function fromString(string $status): self
    {
        return match ($status) {
            'pending' => self::PENDING,
            'completed' => self::COMPLETED,
            'failed' => self::FAILED,
            default => throw new \InvalidArgumentException("Unknown status: $status"),
        };
    }

    public function toString(): string
    {
        return match ($this) {
            self::PENDING => 'pending',
            self::COMPLETED => 'completed',
            self::FAILED => 'failed',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
        };
    }
}
```

## 2. Класс ProcessEmailVerificationReply

Создайте файл `app/Services/Agent/Jobs/ProcessEmailVerificationReply.php`:

```php
<?php

namespace App\Services\Agent\Jobs;

use App\Services\ProfileBot\Contracts\ProfileBotRepoInterface;
use App\Services\Status\Enums\EmailVerificationStatus;
use App\Services\Status\Enums\TaskStatus;
use App\Services\TaskManager\Contracts\TaskManagerRepoInterface;
use Illuminate\Support\Facades\DB;

class ProcessEmailVerificationReply
{
    public function __construct(
        private TaskManagerRepoInterface $taskManagerRepo,
        private ProfileBotRepoInterface $profileBotRepo
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
                    'email_verification_status_id' => EmailVerificationStatus::COMPLETED->value
                ], $profileBot->id);
            }

            if ($statusEnum === TaskStatus::FAILED) {
                if ($profileBot->email_verification_status_id === EmailVerificationStatus::COMPLETED->value) {
                    return;
                }
                $this->taskManagerRepo->update(['error_message' => $errorMessage], $taskManager->id);
                $this->profileBotRepo->update([
                    'email_verification_status_id' => EmailVerificationStatus::FAILED->value
                ], $profileBot->id);
            }
        });
    }
}
```

## Дополнительные изменения

1. Добавьте `email_verification_status_id` в `$fillable` модели `ProfileBot`:

```20:34:app/Services/ProfileBot/Models/ProfileBot.php
    protected $fillable = [
        'ads_power_id',
        'status_id',
        'user_id',
        'cookie_status_id',
        'email_login',
        'email_password',
        'reddit_login',
        'reddit_password',
        'reddit_reg_status_id',
        'email_verification_status_id',
        'sprint_id',
        'platform',
        'proxy_id',
        'ads_group_name',
    ];
```

2. Добавьте метод связи в модель `ProfileBot` (опционально, для удобства):

```php
public function emailVerificationStatus(): BelongsTo
{
    return $this->belongsTo(Status::class, 'email_verification_status_id');
}
```

3. Используйте класс в `RabbitMqJob`, добавив обработку типа задачи `email_verification` (если нужно).

Класс следует той же логике, что и `ProcessBrowserReply`: обновляет статус задачи и статус верификации email в `ProfileBot` в зависимости от результата.