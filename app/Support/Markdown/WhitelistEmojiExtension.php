<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Registers the whitelist emoji parser ahead of ElGigi's, so staff-assigned shortcodes win and everything else falls
 * through to the vendor's map.
 */
final class WhitelistEmojiExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new WhitelistEmojiParser(), 10);
    }
}
