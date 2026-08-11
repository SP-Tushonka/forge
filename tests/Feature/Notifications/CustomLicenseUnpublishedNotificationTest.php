<?php

declare(strict_types=1);

use App\Models\License;
use App\Models\Mod;
use App\Models\User;
use App\Notifications\CustomLicenseUnpublishedNotification;

beforeEach(function (): void {
    $this->license = License::query()->firstOrCreate(['name' => License::CUSTOM_NAME], ['link' => '']);
    $this->mod = Mod::factory()->create(['name' => 'Broken Licence Mod', 'license_id' => $this->license->id]);
});

describe('Channel Selection', function (): void {
    it('always sends via the database channel', function (): void {
        $user = User::factory()->create(['email_moderation_notifications_enabled' => false]);

        expect(new CustomLicenseUnpublishedNotification($this->mod)->via($user))->toBe(['database']);
    });

    it('sends via mail when the moderation email preference is enabled', function (): void {
        $user = User::factory()->create(['email_moderation_notifications_enabled' => true]);

        expect(new CustomLicenseUnpublishedNotification($this->mod)->via($user))->toContain('mail');
    });
});

describe('Mail Message Content', function (): void {
    it('names the mod and links to it', function (): void {
        $user = User::factory()->create();

        $message = new CustomLicenseUnpublishedNotification($this->mod)->toMail($user);
        $body = implode(' ', $message->introLines).' '.implode(' ', $message->outroLines);

        expect($message->subject)->toBe('Your mod Broken Licence Mod has been unpublished')
            ->and($body)->toContain('Broken Licence Mod')
            ->toContain($this->mod->detail_url)
            ->toContain('until you fix your license on your mod')
            ->and($message->actionUrl)->toBe($this->mod->detail_url);
    });
});

describe('Database Notification Data', function (): void {
    it('renders the stored payload through the presenter', function (): void {
        $user = User::factory()->create();

        $record = $user->notifications()->create([
            'id' => fake()->uuid(),
            'type' => CustomLicenseUnpublishedNotification::class,
            'data' => new CustomLicenseUnpublishedNotification($this->mod)->toArray($user),
            'read_at' => null,
        ]);

        $presentation = CustomLicenseUnpublishedNotification::presentDatabaseNotification($record);

        expect($presentation->headline[0]->text)->toBe('Broken Licence Mod')
            ->and($presentation->headline[1]->text)->toContain('unpublished until you fix your license')
            ->and($presentation->url)->toBe($this->mod->detail_url);
    });
});
