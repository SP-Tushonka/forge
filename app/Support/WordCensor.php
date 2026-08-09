<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Masks configured words in user generated text/inputs. Applied by write time model mutators on mod names, teasers, and
 * descriptions, comment bodies and chat messages. Matching is case insensitve and detects common character
 * substitutions (4 for A, 5 for S, and so on...). Matches are replaced with their first character followed by asterisks.
 * Words inside URLs and domain names are left untouched so posted links keep working.
 */
final readonly class WordCensor
{
    /**
     * URL Pattern, we dont wanna touch these!
     */
    private const string URL_PATTERN = '~(?:https?://|www\.)\S+|[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)+~iu';

    /**
     * substitutes for usual letters, people will... uh... find a way
     */
    private const array SUBSTITUTIONS = [
        'a' => 'a@4',
        'b' => 'b8',
        'e' => 'e3',
        'g' => 'g9',
        'i' => 'i1!|',
        'l' => 'l1!|',
        'o' => 'o0',
        's' => 's5$',
        't' => 't7+',
        'z' => 'z2',
    ];

    private ?string $pattern;

    /**
     * @param  array<int, string>  $words
     */
    public function __construct(array $words)
    {
        $this->pattern = $this->compile($words);
    }

    /**
     * Build a censor from the comma separated word list in the censor config
     */
    public static function fromConfig(): self
    {
        return new self(explode(',', config()->string('censor.words', '')));
    }

    /**
     * Replace every configured word in the text with its first character followed by asterisks.
     */
    public function censor(?string $text): ?string
    {
        if ($this->pattern === null || $text === null || $text === '') {
            return $text;
        }

        // Mask around URL spans rather than inside them, so posted links keep working
        $result = '';
        $cursor = 0;

        foreach ($this->urlSpans($text) as [$start, $end]) {
            $result .= $this->mask(mb_substr($text, $cursor, $start - $cursor, '8bit'));
            $result .= mb_substr($text, $start, $end - $start, '8bit');
            $cursor = $end;
        }

        return $result.$this->mask(mb_substr($text, $cursor, null, '8bit'));
    }

    /**
     * mask every configured word in a URL free segment of text
     */
    private function mask(string $text): string
    {
        if ($this->pattern === null || $text === '') {
            return $text;
        }

        $result = preg_replace_callback(
            $this->pattern,
            // if something is all numbers, just dont
            fn (array $match): string => preg_match('/\p{L}/u', $match[0]) === 1
                ? mb_substr($match[0], 0, 1).str_repeat('*', mb_strlen($match[0]) - 1)
                : $match[0],
            $text
        );

        // PCRE returns null on invalid UTF-8, fail open instead of blanking the user content
        return $result ?? $text;
    }

    /**
     * Byte offset ranges of every URL looking region, ascending and not overlapping
     * we will skip these
     *
     * @return array<int, array{int, int}>
     */
    private function urlSpans(string $text): array
    {
        if (preg_match_all(self::URL_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        return array_map(
            fn (array $match): array => [$match[1], $match[1] + mb_strlen($match[0], '8bit')],
            $matches[0]
        );
    }

    /**
     * Compile the word list into a single alternation pattern. The lookarounds keep matches anchored to standalone
     * words so that a censored "grape" does not mangle "grapefruit"
     *
     * @param  array<int, string>  $words
     */
    private function compile(array $words): ?string
    {
        // single character words are dropped: the mask keeps the first character, so they could never be hidden
        $words = array_values(array_filter(
            array_map(mb_trim(...), $words),
            fn (string $word): bool => mb_strlen($word) >= 2
        ));

        if ($words === []) {
            return null;
        }

        $alternatives = array_map(
            fn (string $word): string => implode('', array_map(
                $this->characterPattern(...),
                mb_str_split(mb_strtolower($word))
            )),
            $words
        );

        return '/(?<![\p{L}\p{N}])(?:'.implode('|', $alternatives).')(?![\p{L}\p{N}])/iu';
    }

    /**
     * pattern for a single character of a censored word, widened to its substitution class when one exists
     */
    private function characterPattern(string $character): string
    {
        $substitutes = self::SUBSTITUTIONS[$character] ?? null;

        if ($substitutes === null) {
            return preg_quote($character, '/');
        }

        return '['.preg_quote($substitutes, '/').']';
    }
}
