<?php

namespace Tests\Parity\Suite;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Test-only notification used to pin the notification machinery the app relies
 * on (the User model is Notifiable; the app itself sends no notifications yet,
 * so the contract under test is channel routing + payload shape).
 */
class ParityTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Parity notification')
            ->line('Snapshot fixture for the notification contract.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'parity_snapshot',
            'user' => $notifiable->email ?? null,
        ];
    }
}
