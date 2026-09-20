<?php

declare(strict_types=1);

namespace App\Enums;

enum ModIssueType: string
{
    case Bug = 'bug';

    case Feature = 'feature';

    case Question = 'question';

    case Compatibility = 'compatibility';

    private const string LOG_PROMPT = 'Upload your log files to https://codepaste.sp-mod.com and paste the link here.';

    /**
     * Every template that exists, so a form can tell an untouched template from something the reporter wrote.
     *
     * @return list<string>
     */
    public static function templates(): array
    {
        return array_values(array_filter(array_map(
            fn (self $type): string => $type->template(),
            self::cases(),
        )));
    }

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Feature => 'Feature request',
            self::Question => 'Question',
            self::Compatibility => 'Compatibility',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Bug => 'bug-ant',
            self::Feature => 'light-bulb',
            self::Question => 'question-mark-circle',
            self::Compatibility => 'puzzle-piece',
        };
    }

    /**
     * Whether the reporter is offered the affected version picker at all.
     */
    public function showsAffectedVersion(): bool
    {
        return $this !== self::Feature;
    }

    /**
     * A bug and a conflict always happen on a particular release; a question may be about the mod in general.
     */
    public function requiresAffectedVersion(): bool
    {
        return $this === self::Bug || $this === self::Compatibility;
    }

    /**
     * The starting body for a new issue of this type. Empty when a prompt would only get in the way.
     */
    public function template(): string
    {
        return match ($this) {
            self::Bug => "**Steps to reproduce**\n1. \n\n**What you expected**\n\n\n**What happened**\n\n\n**Logs**\n".self::LOG_PROMPT,
            self::Compatibility => "**Mods involved**\n\n\n**What happens together**\n\n\n**What happens with this mod alone**\n\n\n**Logs**\n".self::LOG_PROMPT,
            self::Question => "**What are you trying to do?**\n\n\n**What have you tried?**",
            self::Feature => '',
        };
    }
}
