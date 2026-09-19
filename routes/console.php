<?php

declare(strict_types=1);

use App\Console\Commands\CensorExistingContentCommand;
use App\Console\Commands\CleanupOldNotificationLogs;
use App\Console\Commands\EnsureFavouritesLists;
use App\Console\Commands\ForgeHeartbeat;
use App\Console\Commands\SyncModStatsCommand;
use App\Console\Commands\UpdateGeoLiteDatabase;
use App\Jobs\AggregateApiUsageDailyJob;
use App\Jobs\AggregateApiUsageJob;
use App\Jobs\AuditCustomLicensedModsJob;
use App\Jobs\CleanupStaleVerificationsJob;
use App\Jobs\CleanupVerificationArtifactsJob;
use App\Jobs\ExpireStaleModClaimsJob;
use App\Jobs\FetchCloudflareApiAnalyticsJob;
use App\Jobs\FetchCloudflareVisitorStatsJob;
use App\Jobs\NotifyReleasedIssueFixes;
use App\Jobs\ProcessPinnedModVersionPublishDates;
use App\Jobs\PruneModStatsJob;
use App\Jobs\SearchSyncJob;
use App\Jobs\SendDiscordNotifications;
use App\Jobs\UpdateDisposableEmailBlocklist;
use App\Jobs\UpdateEndorsementsJob;
use App\Jobs\UpdateFavouritesJob;
use App\Jobs\VerificationSweepJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
Schedule::command(CleanupOldNotificationLogs::class)->daily()->onOneServer();
Schedule::command(EnsureFavouritesLists::class)->daily()->onOneServer();
Schedule::job(new UpdateFavouritesJob)->hourly()->onOneServer()->withoutOverlapping();
Schedule::job(new UpdateEndorsementsJob)->hourly()->onOneServer()->withoutOverlapping();
Schedule::job(new ExpireStaleModClaimsJob)->hourly()->onOneServer()->withoutOverlapping();
Schedule::job(new NotifyReleasedIssueFixes)->everyTenMinutes()->onOneServer()->withoutOverlapping();
Schedule::job(new AuditCustomLicensedModsJob)->daily()->at('01:00')->onOneServer()->withoutOverlapping();

// Mod stats dashboard: reconcile today and yesterday every 15 minutes, re-check the rest of Cloudflare's 30-day window
// nightly, then drop rows past the 240-day retention.
Schedule::command(SyncModStatsCommand::class, ['--from=1', '--to=0'])->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command(SyncModStatsCommand::class, ['--from=30', '--to=2'])->dailyAt('03:00')->onOneServer();
Schedule::job(new PruneModStatsJob)->dailyAt('03:30')->onOneServer();

Schedule::command(UpdateGeoLiteDatabase::class)->daily()->at('02:00')->onOneServer()->runInBackground()->environments('production');
Schedule::command(CensorExistingContentCommand::class, ['--force', '--if-changed'])->daily()->at('02:30')->onOneServer()->withoutOverlapping()->environments('production');
Schedule::job(new SearchSyncJob)->daily()->at('03:00')->onOneServer()->environments('production');
Schedule::job(new UpdateDisposableEmailBlocklist)->daily()->at('04:00')->onOneServer()->environments('production');

Schedule::job(new SendDiscordNotifications)->everyMinute()->onOneServer()->withoutOverlapping()->environments('production');
Schedule::job(new ProcessPinnedModVersionPublishDates)->everyMinute()->onOneServer()->withoutOverlapping()->environments('production');

if (config('app.forge_heartbeat_url')) {
    Schedule::command(ForgeHeartbeat::class)->everyMinute()->onOneServer()->withoutOverlapping();
}

if (config('verification.auto_enabled')) {
    Schedule::job(new VerificationSweepJob)->twiceDaily(6, 18)->onOneServer()->withoutOverlapping();
    Schedule::job(new CleanupStaleVerificationsJob)->hourly()->onOneServer()->withoutOverlapping();
    Schedule::job(new CleanupVerificationArtifactsJob)->hourly()->onOneServer()->withoutOverlapping();
}

// Drain the API usage counters every minute and roll them up daily. Gated on the same flag that enables recording so
// capture and aggregation are turned on together.
if (config('api.usage.enabled')) {
    Schedule::job(new AggregateApiUsageJob)->everyMinute()->onOneServer()->withoutOverlapping();
    Schedule::job(new AggregateApiUsageDailyJob)->dailyAt('00:15')->onOneServer();
}

// Refresh the footer's Cloudflare figures: the open API's edge request totals and the recent-visitor count.
if (config('services.cloudflare.analytics_token') && config('services.cloudflare.zone_id')) {
    Schedule::job(new FetchCloudflareApiAnalyticsJob)->everyFiveMinutes()->onOneServer()->withoutOverlapping();
    Schedule::job(new FetchCloudflareVisitorStatsJob)->everyFiveMinutes()->onOneServer()->withoutOverlapping();
}

if (app()->isLocal() && config('telescope.enabled')) {
    Schedule::command('telescope:prune --hours=48')->daily();
}

Schedule::command('nightowl:prune')->daily()->at('05:00')->onOneServer()->withoutOverlapping()->runInBackground()->environments('production');
