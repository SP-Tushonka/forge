<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Jobs\NormalizeUserAvatar;
use App\Models\User;
use App\Support\ArchivedAccountLookupLimiter;
use App\Support\DataTransferObjects\ImageCropRect;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function makeProfileUpdateInput(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'timezone' => 'America/New_York',
        'about' => 'Short about text.',
        'photo' => UploadedFile::fake()->image('avatar.png', 512, 512),
    ], $overrides);
}

beforeEach(function (): void {
    Storage::fake('public');
    Queue::fake([NormalizeUserAvatar::class]);
});

it('passes a valid crop rect to the avatar normalization job as a DTO', function (): void {
    $user = User::factory()->create();

    resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user, [
        'photoCropRect' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 300],
    ]));

    Queue::assertPushed(fn (NormalizeUserAvatar $job): bool => $job->user->is($user)
        && $job->cropRect?->x === 10
        && $job->cropRect->y === 20
        && $job->cropRect->width === 300
        && $job->cropRect->height === 300);
});

it('passes a null crop rect when none is provided', function (): void {
    $user = User::factory()->create();

    resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user));

    Queue::assertPushed(fn (NormalizeUserAvatar $job): bool => ! $job->cropRect instanceof ImageCropRect);
});

it('rejects a crop rect with a missing key', function (): void {
    $user = User::factory()->create();

    resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user, [
        'photoCropRect' => ['x' => 0, 'y' => 0, 'width' => 300],
    ]));
})->throws(ValidationException::class);

it('rejects a crop rect with a negative origin', function (): void {
    $user = User::factory()->create();

    resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user, [
        'photoCropRect' => ['x' => -5, 'y' => 0, 'width' => 300, 'height' => 300],
    ]));
})->throws(ValidationException::class);

it('rejects a crop rect below the minimum dimensions', function (): void {
    $user = User::factory()->create();

    resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user, [
        'photoCropRect' => ['x' => 0, 'y' => 0, 'width' => 64, 'height' => 64],
    ]));
})->throws(ValidationException::class);

it('refuses an email change onto an address an archived account still holds', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $original = $user->email;

    // The archived row stores a placeholder, so unique:users sees the real address as free. Taking it would strand the
    // owner for good: recovery refuses to hand the account back while a live row holds the address. Mixed case on
    // purpose, since the tombstone is the only case-insensitive check in the path.
    $archived = User::factory()->create([
        'email_tombstone' => User::emailTombstoneFor('returning@example.com'),
    ]);

    try {
        resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user, [
            'email' => 'Returning@Example.com',
            'photo' => null,
            'cover' => UploadedFile::fake()->image('cover.png', 1200, 400),
        ]));

        $this->fail('Expected a ValidationException for the archived address.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('email')
            ->and($validationException->errors()['email'][0])->toContain('old Forge')
            ->and($validationException->errors()['email'][0])->toContain(route('account.recovery.request'));
    }

    // The cover pins the guard's position: it is written before the email branch, so a guard that ran any later would
    // leave the profile half updated behind a refused address.
    expect($user->refresh()->email)->toBe($original)
        ->and($user->cover_photo_path)->toBeNull()
        ->and($archived->refresh()->email_tombstone)->not->toBeNull();
});

it('spends no archived-account budget when the address is unchanged', function (): void {
    $user = User::factory()->create();

    resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user, ['photo' => null]));

    expect(RateLimiter::attempts(ArchivedAccountLookupLimiter::key()))->toBe(0);
});

it('stops looking archived accounts up once the per-IP limit is spent', function (): void {
    $user = User::factory()->create();

    User::factory()->create([
        'email_tombstone' => User::emailTombstoneFor('returning@example.com'),
    ]);

    foreach (range(1, config()->integer('recovery.max_attempts')) as $ignored) {
        RateLimiter::hit(ArchivedAccountLookupLimiter::key(), config()->integer('recovery.decay_seconds'));
    }

    try {
        resolve(UpdateUserProfileInformation::class)->update($user, makeProfileUpdateInput($user, [
            'email' => 'returning@example.com',
            'photo' => null,
        ]));

        $this->fail('Expected the per-IP limiter to reject the lookup.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['email'][0])->toBe('Too many attempts. Please try again later.');
    }

    expect($user->refresh()->email)->not->toBe('returning@example.com');
});
