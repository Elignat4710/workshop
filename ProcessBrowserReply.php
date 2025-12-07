<?php

namespace App\Services\Agent\Jobs;

use App\Services\ProfileBot\Contracts\ProfileBotRepoInterface;
use App\Services\Status\Enums\RedditRegStatus;
use App\Services\Status\Enums\TaskStatus;
use App\Services\TaskManager\Contracts\TaskManagerRepoInterface;
use Illuminate\Support\Facades\DB;

class ProcessBrowserReply
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
                    'reddit_reg_status_id' => RedditRegStatus::COMPLETED->value
                ], $profileBot->id);
            }

            if ($statusEnum === TaskStatus::FAILED) {
                if ($profileBot->reddit_reg_status_id === RedditRegStatus::COMPLETED->value) {
                    return;
                }
                $this->taskManagerRepo->update(['error_message' => $errorMessage], $taskManager->id);
                $this->profileBotRepo->update([
                    'reddit_reg_status_id' => RedditRegStatus::FAILED->value
                ], $profileBot->id);
            }
        });
    }
}
