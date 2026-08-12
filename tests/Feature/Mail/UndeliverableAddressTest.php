<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\VerifyEmail;
use App\Support\UndeliverableAddress;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Messages actually handed to the transport
 */
function sentMessages(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()->all();
}

describe('UndeliverableAddress', function (): void {
    it('rejects reserved TLDs that can never resolve', function (string $email): void {
        expect(UndeliverableAddress::check($email))->toBeTrue();
    })->with([
        '10030@unclaimed.invalid',
        'someone@unclaimed.INVALID',
        'a@b.test',
        'a@b.example',
        'a@b.localhost',
        'no-at-sign',
        '',
    ]);

    it('allows real addresses', function (string $email): void {
        expect(UndeliverableAddress::check($email))->toBeFalse();
    })->with([
        'someone@gmail.com',
        'someone@proton.me',
        'someone@sp-mod.com',
        'someone@invalid-looking-but-real.com',
        'someone@sub.invalid.com',
    ]);

    it('filters a list down to deliverable addresses', function (): void {
        expect(UndeliverableAddress::deliverable([
            'a@gmail.com', '1@unclaimed.invalid', 'b@proton.me', '2@unclaimed.invalid',
        ]))->toBe(['a@gmail.com', 'b@proton.me']);
    });
});

describe('outbound mail suppression', function (): void {
    it('never hands a message for an archived placeholder to the transport', function (): void {
        Mail::raw('should not be delivered', function ($message): void {
            $message->to('10030@unclaimed.invalid')->subject('Blocked');
        });

        expect(sentMessages())->toBeEmpty();
    });

    it('still delivers to a normal address', function (): void {
        Mail::raw('should be delivered', function ($message): void {
            $message->to('real.person@example.org')->subject('Allowed');
        });

        expect(sentMessages())->toHaveCount(1);
    });

    it('blocks the whole message when any recipient is undeliverable', function (): void {
        // Delivering to the rest would leak the fact that the placeholder was on the message.
        Mail::raw('mixed recipients', function ($message): void {
            $message->to('real.person@example.org')->cc('10030@unclaimed.invalid')->subject('Mixed');
        });

        expect(sentMessages())->toBeEmpty();
    });

    it('does not route notifications for an archived user', function (): void {
        $archived = User::factory()->create([
            'email' => '10030@unclaimed.invalid',
            'email_tombstone' => User::emailTombstoneFor('real@example.com'),
        ]);

        expect($archived->routeNotificationForMail())->toBeNull();

        $archived->notify(new VerifyEmail);

        expect(sentMessages())->toBeEmpty();
    });

    it('still routes notifications for a recovered user', function (): void {
        $recovered = User::factory()->create([
            'email' => 'recovered@example.org',
            'email_tombstone' => null,
        ]);

        expect($recovered->routeNotificationForMail())->toBe('recovered@example.org');
    });
});

describe('notification fake still observes the block', function (): void {
    it('reports no mail channel delivery for archived users', function (): void {
        Notification::fake();

        $archived = User::factory()->create(['email' => '999@unclaimed.invalid']);
        $archived->notify(new VerifyEmail);

        Notification::assertSentTo($archived, VerifyEmail::class);
    });
});
