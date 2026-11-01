<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\ReminderStatus;
use App\Models\Document;
use App\Models\DocumentReminder;
use Illuminate\Support\Carbon;

class DocumentReminderSync
{
    public const LEAD_DAYS = 30;

    public function __construct(private DocumentCapabilities $capabilities) {}

    public function sync(Document $document): void
    {
        if ($document->status !== DocumentStatus::Filed) {
            $this->cancelPending($document);

            return;
        }

        $dates = $this->capabilities->actionableExpiryDates($document);
        $activeRoles = [];

        foreach ($dates as $date) {
            $activeRoles[] = $date['role'];
            $this->upsertPending($document, $date['role'], $date['value']);
        }

        $stale = DocumentReminder::query()
            ->where('document_id', $document->id)
            ->where('status', ReminderStatus::Pending);

        if ($activeRoles === []) {
            $stale->update(['status' => ReminderStatus::Cancelled]);
        } else {
            $stale->whereNotIn('date_role', $activeRoles)
                ->update(['status' => ReminderStatus::Cancelled]);
        }
    }

    public function cancelPending(Document $document): void
    {
        DocumentReminder::query()
            ->where('document_id', $document->id)
            ->where('status', ReminderStatus::Pending)
            ->update(['status' => ReminderStatus::Cancelled]);
    }

    private function upsertPending(Document $document, string $role, string $sourceDate): void
    {
        $source = Carbon::parse($sourceDate)->startOfDay();
        $remindOn = $source->copy()->subDays(self::LEAD_DAYS);
        $today = Carbon::today();

        if ($remindOn->lt($today)) {
            $remindOn = $today->copy();
        }

        $existing = DocumentReminder::query()
            ->where('document_id', $document->id)
            ->where('date_role', $role)
            ->first();

        if ($existing
            && in_array($existing->status, [ReminderStatus::Sent, ReminderStatus::Dismissed], true)
            && $existing->source_date?->equalTo($source)) {
            return;
        }

        DocumentReminder::query()->updateOrCreate(
            [
                'document_id' => $document->id,
                'date_role' => $role,
            ],
            [
                'profile_id' => $document->profile_id,
                'user_id' => $document->user_id,
                'source_date' => $source->toDateString(),
                'remind_on' => $remindOn->toDateString(),
                'status' => ReminderStatus::Pending,
                'channel' => 'database',
                'sent_at' => null,
            ],
        );
    }
}
