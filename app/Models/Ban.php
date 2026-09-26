<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Mchev\Banhammer\Models\Ban as BaseBan;
use Override;

/**
 * @property int $id
 * @property string|null $bannable_type
 * @property int|null $bannable_id
 * @property string|null $created_by_type
 * @property int|null $created_by_id
 * @property string|null $comment
 * @property string|null $ip
 * @property list<string>|null $subject_emails
 * @property list<string>|null $subject_ips
 * @property CarbonImmutable|null $expired_at
 * @property array<string, mixed>|null $metas
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Ban extends BaseBan
{
    /** @use HasFactory<BanFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'subject_emails' => 'array',
            'subject_ips' => 'array',
        ];
    }
}
