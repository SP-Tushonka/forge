<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CommentVersion;
use App\Models\Message;
use App\Models\Mod;
use App\Support\WordCensor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Description('Applies the configured word censor to existing mod names, teasers, descriptions, comments, and chat messages')]
#[Signature('censor:existing
    {--dry-run : Report how many records would change without saving anything}
    {--force : Run without the confirmation prompt}
    {--if-changed : Only sweep when the word list changed since the last completed run}')]
final class CensorExistingContentCommand extends Command
{
    /**
     * The censorable text attributes per model
     */
    private const array TARGETS = [
        Mod::class => ['name', 'teaser', 'description'],
        CommentVersion::class => ['body', 'translated_body'],
        Message::class => ['content'],
    ];

    /**
     * On the local disk rather than in cache: the nightly SearchSyncJob starts with cache:clear, which would wipe
     * a cached hash and force a full sweep every night
     */
    private const string WORDS_HASH_PATH = 'censor-words.hash';

    public function handle(): int
    {
        $words = config()->string('censor.words', '');

        if ($words === '') {
            $this->warn('No censored words configured (CENSOR_WORDS is empty).');

            return self::SUCCESS;
        }

        $hash = hash('sha256', $words);

        if ($this->option('if-changed') && Storage::disk('local')->get(self::WORDS_HASH_PATH) === $hash) {
            $this->info('Word list unchanged since the last sweep; nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('This permanently rewrites stored content containing censored words. Continue?')) {
            return self::SUCCESS;
        }

        $censor = WordCensor::fromConfig();

        foreach (self::TARGETS as $model => $attributes) {
            $changed = 0;

            $model::query()->withoutGlobalScopes()->chunkById(
                200,
                /** @param  Collection<int, Model>  $records */
                function (Collection $records) use ($censor, $attributes, $dryRun, &$changed): void {
                    foreach ($records as $record) {
                        if ($this->censorRecord($record, $censor, $attributes, $dryRun)) {
                            $changed++;
                        }
                    }
                }
            );

            $this->info(sprintf('%s: %d record(s) %s.', class_basename($model), $changed, $dryRun ? 'would change' : 'updated'));
        }

        if (! $dryRun) {
            Storage::disk('local')->put(self::WORDS_HASH_PATH, $hash);
            $this->comment('Records were saved quietly. Run "php artisan app:search-sync" to reindex Meilisearch (the 03:00 scheduled sync also covers it).');
        }

        return self::SUCCESS;
    }

    /**
     * Censor the given attributes on a record, returning whether any of them matched
     *
     * @param  array<int, string>  $attributes
     */
    private function censorRecord(Model $record, WordCensor $censor, array $attributes, bool $dryRun): bool
    {
        $dirty = false;

        foreach ($attributes as $attribute) {
            $original = $record->getRawOriginal($attribute);
            if (! is_string($original)) {
                continue;
            }

            if ($original === '') {
                continue;
            }

            $censored = $censor->censor($original);

            if ($censored !== $original) {
                // setAttribute reruns the model censor mutator on the already masked value, that second pass is
                // an intentional noop since masked text no longer matches.
                $record->setAttribute($attribute, $censored);
                $dirty = true;
            }
        }

        if ($dirty && ! $dryRun) {
            $record->timestamps = false;
            $record->saveQuietly();
        }

        return $dirty;
    }
}
