<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CommentVersion;
use App\Models\Message;
use App\Models\Mod;
use App\Support\WordCensor;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One-off repair: the word censor used to mask with ASCII asterisks, which markdown paired with the author's own emphasis
 * markers and swallowed text. Converts those stored masks to WordCensor::MASK. Safe to delete once it has run in production.
 */
#[Description('Converts word censor masks stored as ASCII asterisks in markdown content (mod descriptions, comments, chat messages)')]
#[Signature('censor:repair-masks
    {--dry-run : Report how many records would change without saving anything}
    {--force : Run without the confirmation prompt}')]
final class RepairCensorMasksCommand extends Command
{
    /**
     * The markdown attributes censored before the mask character changed
     */
    private const array TARGETS = [
        Mod::class => ['description'],
        CommentVersion::class => ['body', 'translated_body'],
        Message::class => ['content'],
    ];

    public function handle(): int
    {
        $lengths = $this->maskLengths();

        if ($lengths === []) {
            $this->warn('No censored words configured (CENSOR_WORDS is empty).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('This rewrites stored markdown content containing censor masks. Continue?')) {
            return self::SUCCESS;
        }

        foreach (self::TARGETS as $model => $attributes) {
            $changed = 0;

            $model::query()->withoutGlobalScopes()->chunkById(
                200,
                /** @param  Collection<int, Model>  $records */
                function (Collection $records) use ($lengths, $attributes, $dryRun, &$changed): void {
                    foreach ($records as $record) {
                        if ($this->repairRecord($record, $lengths, $attributes, $dryRun)) {
                            $changed++;
                        }
                    }
                }
            );

            $this->info(sprintf('%s: %d record(s) %s.', class_basename($model), $changed, $dryRun ? 'would change' : 'updated'));
        }

        if (! $dryRun) {
            $this->comment('Records were saved quietly. Run "php artisan app:search-sync" to reindex Meilisearch (the 03:00 scheduled sync also covers it).');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<int, int>>  $lengths
     * @param  array<int, string>  $attributes
     */
    private function repairRecord(Model $record, array $lengths, array $attributes, bool $dryRun): bool
    {
        $dirty = false;

        foreach ($attributes as $attribute) {
            $original = $record->getRawOriginal($attribute);

            if (! is_string($original) || ! str_contains($original, '*')) {
                continue;
            }

            $repaired = $this->repair($original, $lengths);

            if ($repaired !== $original) {
                $record->setAttribute($attribute, $repaired);
                $dirty = true;
            }
        }

        if ($dirty && ! $dryRun) {
            $record->timestamps = false;
            $record->saveQuietly();
        }

        return $dirty;
    }

    /**
     * Emphasis never spans a blank line, so each block is settled on its own. That keeps every render small: rendering
     * the whole document per candidate takes minutes on the longest descriptions.
     *
     * @param  array<string, array<int, int>>  $lengths
     */
    private function repair(string $text, array $lengths): string
    {
        $blocks = preg_split('/(\R\h*\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($blocks === false) {
            return $text;
        }

        return implode('', array_map(fn (string $block): string => $this->repairBlock($block, $lengths), $blocks));
    }

    /**
     * A stored mask can look exactly like the author's emphasis ("**B**" is a bold B or a bolded, masked "BSG"), so each
     * candidate is settled by rendering: it gets whichever reading (no mask, or a mask of one of the word lengths) leaves
     * the fewest asterisks visible, ties going to the shortest. Meanwhile the other candidates are read as masks.
     *
     * @param  array<string, array<int, int>>  $lengths
     */
    private function repairBlock(string $text, array $lengths): string
    {
        preg_match_all('/(?<![\p{L}\p{N}])(\p{L})(\*+)(?![\p{L}\p{N}])/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $candidates = [];
        $chosen = [];

        foreach ($matches as [, $letter, $run]) {
            $fits = array_values(array_filter(
                $lengths[mb_strtolower($letter[0])] ?? [],
                fn (int $length): bool => $length <= mb_strlen($run[0])
            ));

            if ($fits !== []) {
                $candidates[] = ['offset' => $run[1], 'fits' => $fits];
                $chosen[] = max($fits);
            }
        }

        foreach ($candidates as $i => $candidate) {
            $best = 0;
            $fewest = PHP_INT_MAX;

            foreach ([0, ...$candidate['fits']] as $length) {
                $chosen[$i] = $length;
                $visible = mb_substr_count(strip_tags(Markdown::convert($this->apply($text, $candidates, $chosen))->getContent()), '*');

                if ($visible < $fewest) {
                    $best = $length;
                    $fewest = $visible;
                }
            }

            $chosen[$i] = $best;
        }

        return $this->apply($text, $candidates, $chosen);
    }

    /**
     * Swap the chosen number of leading asterisks of each candidate run for the mask character
     *
     * @param  array<int, array{offset: int, fits: array<int, int>}>  $candidates
     * @param  array<int, int>  $chosen
     */
    private function apply(string $text, array $candidates, array $chosen): string
    {
        // Back to front, so the byte offsets of earlier runs stay valid
        foreach (array_reverse($candidates, true) as $i => $candidate) {
            $text = substr_replace($text, str_repeat(WordCensor::MASK, $chosen[$i]), $candidate['offset'], $chosen[$i]);
        }

        return $text;
    }

    /**
     * Mask lengths (characters after the first) per lowercase first letter, shortest first. Only letter led masks are
     * repaired: one kept from a substituted spelling ("8**" for "8SG") is too easily the author's own "**8**".
     *
     * @return array<string, array<int, int>>
     */
    private function maskLengths(): array
    {
        $lengths = [];

        foreach (explode(',', config()->string('censor.words', '')) as $word) {
            $word = mb_trim($word);

            if (mb_strlen($word) >= 2 && preg_match('/^\p{L}/u', $word) === 1) {
                $lengths[mb_strtolower(mb_substr($word, 0, 1))][] = mb_strlen($word) - 1;
            }
        }

        return array_map(function (array $wordLengths): array {
            $wordLengths = array_values(array_unique($wordLengths));
            sort($wordLengths);

            return $wordLengths;
        }, $lengths);
    }
}
