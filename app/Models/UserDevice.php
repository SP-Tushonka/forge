<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $device_hash
 * @property string|null $name
 * @property string|null $browser
 * @property string|null $platform
 * @property string|null $device_type
 * @property string|null $useragent
 * @property string|null $last_ip
 * @property string|null $country_code
 * @property string|null $city_name
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 */
final class UserDevice extends Model
{
    /** @use HasFactory<UserDeviceFactory> */
    use HasFactory;

    public static function describe(?string $browser, ?string $platform): string
    {
        return ($browser ?: 'Unknown browser').' on '.($platform ?: 'unknown system');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user's nickname for the device, or a description of its browser and system.
     */
    public function label(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : self::describe($this->browser, $this->platform);
    }

    public function location(): ?string
    {
        $parts = array_filter([$this->city_name, $this->country_code]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
