<?php

namespace App\Console\Commands;

use App\Enums\ReminderStatus;
use App\Models\DocumentReminder;
use App\Notifications\DocumentExpiryReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendDocumentExpiryRemindersCommand extends Command
{
    protected $signature = 'documents:send-expiry-reminders';

    protected $description = 'Send pending document expiry/renewal reminders that are due';

    public function handle(): int
    {
        $sent = 0;

        DocumentReminder::query()
            ->with(['user', 'document'])
            ->where('status', ReminderStatus::Pending)
            ->whereDate('remind_on', '<=', Carbon::today())
            ->orderBy('id')
            ->chunkById(100, function ($reminders) use (&$sent): void {
                foreach ($reminders as $reminder) {
                    $user = $reminder->user;
                    if ($user === null) {
                        $reminder->forceFill(['status' => ReminderStatus::Cancelled])->save();

                        continue;
                    }

                    $user->notify(new DocumentExpiryReminderNotification($reminder));
                    $reminder->forceFill([
                        'status' => ReminderStatus::Sent,
                        'sent_at' => now(),
                        'channel' => 'database',
                    ])->save();
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
