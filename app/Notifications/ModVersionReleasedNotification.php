<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Presentable;
use App\Enums\NotificationColorRole;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Support\DataTransferObjects\HeadlineSegment;
use App\Support\DataTransferObjects\NotificationPresentation;
use App\Traits\ThrottlesOutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells a mod's subscribers, on-site, that a new version is public. One notification per mod; carries plain values so
 * a queued send survives the mod being deleted before it runs. The per-sweep email digest is sent separately by
 * ModUpdatesDigestNotification.
 */
final class ModVersionReleasedNotification extends Notification implements Presentable, ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(
        public int $modId,
        public string $modName,
        public string $modUrl,
        public string $version,
        public ?string $sptVersion,
    ) {}

    public static function forVersion(Mod $mod, ModVersion $version): self
    {
        return new self(
            modId: $mod->id,
            modName: $mod->name,
            modUrl: $mod->detail_url,
            version: $version->version,
            sptVersion: $version->latestSptVersion?->version_formatted,
        );
    }

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{mod_name?: string, mod_url?: string, version?: string, spt_version?: string|null} $data */
        $data = $record->data;

        $sptVersion = $data['spt_version'] ?? null;

        return new NotificationPresentation(
            iconName: 'bell-alert',
            iconColorRole: NotificationColorRole::Blue,
            headline: [
                HeadlineSegment::strong(Str::limit($data['mod_name'] ?? '', 40)),
                HeadlineSegment::muted(' '.__('released').' '),
                HeadlineSegment::accent('v'.($data['version'] ?? '')),
            ],
            summary: $sptVersion !== null ? __('new version for :spt', ['spt' => $sptVersion]) : __('new version'),
            url: $data['mod_url'] ?? null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{mod_id: int, mod_name: string, mod_url: string, version: string, spt_version: string|null}
     */
    public function release(): array
    {
        return [
            'mod_id' => $this->modId,
            'mod_name' => $this->modName,
            'mod_url' => $this->modUrl,
            'version' => $this->version,
            'spt_version' => $this->sptVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->release();
    }
}
