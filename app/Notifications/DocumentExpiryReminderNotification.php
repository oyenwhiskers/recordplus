<?php

namespace App\Notifications;

use App\Models\DocumentReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DocumentExpiryReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public DocumentReminder $reminder) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $document = $this->reminder->document;
        $sourceDate = $this->reminder->source_date?->format('j M Y');
        $role = $this->reminder->date_role === 'renewal' ? 'renewal' : 'expiry';

        return [
            'document_id' => $this->reminder->document_id,
            'reminder_id' => $this->reminder->id,
            'date_role' => $this->reminder->date_role,
            'source_date' => $this->reminder->source_date?->toDateString(),
            'title' => $document?->display_primary ?? 'Document',
            'message' => $sourceDate
                ? "Reminder: {$role} on {$sourceDate}"
                : "Reminder: document {$role} approaching",
        ];
    }
}
