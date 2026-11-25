<?php

namespace Tests\Parity\Suite;

use App\Models\User;
use App\Support\CmtUser;
use App\Support\Panel\CmtPanelUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Notification parity: the app ships no notification senders yet (Python has
 * none either), so what is pinned here is the notifiable contract the User
 * model and the Filament panel user expose.
 */
class NotificationsParityTest extends ParitySuiteTestCase
{
    /** Minimal notifiable: exercises the notification machinery without a DB. */
    private function notifiable(): object
    {
        return new class
        {
            use Notifiable;

            public string $email = 'parity@example.test';

            public function getKey(): string
            {
                return 'parity-1';
            }

            public function getMorphClass(): string
            {
                return 'parity_notifiable';
            }
        };
    }

    public function test_user_model_is_notifiable(): void
    {
        $this->assertContains(Notifiable::class, class_uses_recursive(User::class));
        $this->assertTrue(method_exists(new User(), 'notify'));
        $this->assertTrue(method_exists(new User(), 'routeNotificationFor'));
    }

    public function test_notification_is_dispatched_to_the_notifiable(): void
    {
        Notification::fake();
        $notifiable = $this->notifiable();

        $notifiable->notify(new ParityTestNotification());

        Notification::assertSentTo($notifiable, ParityTestNotification::class);
        Notification::assertCount(1);
    }

    public function test_notification_channel_routing(): void
    {
        $notification = new ParityTestNotification();
        $notifiable = $this->notifiable();

        $this->assertSame(['mail', 'database'], $notification->via($notifiable));

        $this->snapshotCase('notifications', 'channels', [
            'channels' => $notification->via($notifiable),
        ]);
    }

    public function test_notification_payload_shapes(): void
    {
        $notifiable = $this->notifiable();
        $notification = new ParityTestNotification();

        $mail = $notification->toMail($notifiable);
        $array = $notification->toArray($notifiable);

        $this->assertSame('Parity notification', $mail->subject);
        $this->assertSame('parity_snapshot', $array['event']);

        $this->snapshotCase('notifications', 'payload', [
            'mail_subject' => $mail->subject,
            'mail_lines' => $mail->introLines,
            'array_payload' => $array,
        ]);
    }

    public function test_panel_user_notification_contract(): void
    {
        $panelUser = new CmtPanelUser(new CmtUser(
            username: 'manager1',
            displayName: 'Manager1',
            role: 'management',
            authenticated: true,
        ));

        $this->assertInstanceOf(Authenticatable::class, $panelUser);
        $this->assertNull(
            $panelUser->receivesBroadcastNotificationsOn(),
            'no broadcast layer: Filament skips notification broadcasting entirely'
        );

        $this->snapshotCase('notifications', 'panel_user_contract', [
            'authenticatable' => $panelUser instanceof Authenticatable,
            'broadcast_channel' => $panelUser->receivesBroadcastNotificationsOn(),
            'filament_name' => $panelUser->getFilamentName(),
            'avatar_is_inline_svg' => str_starts_with((string) $panelUser->getFilamentAvatarUrl(), 'data:image/svg+xml;base64,'),
        ]);
    }
}
