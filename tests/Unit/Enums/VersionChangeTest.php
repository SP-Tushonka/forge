<?php

declare(strict_types=1);

use App\Enums\VersionChange;
use App\Enums\VersionTagColor;

it('names the most significant part that differs between two versions', function (string $from, string $to, ?VersionChange $expected): void {
    expect(VersionChange::between($from, $to))->toBe($expected);
})->with([
    'identical' => ['1.2.3', '1.2.3', null],
    'identical apart from a v prefix' => ['v1.2.3', '1.2.3', null],
    'major' => ['1.2.3', '2.0.0', VersionChange::Major],
    'minor' => ['1.2.3', '1.3.0', VersionChange::Minor],
    'patch' => ['1.2.3', '1.2.4', VersionChange::Patch],
    'build metadata only' => ['3.0.3+spt3.11', '3.0.3+spt4.0', VersionChange::Patch],
    'pre-release label only' => ['0.9.0-beta.2', '0.9.0', VersionChange::Patch],
    'newer than the latest' => ['1.3.0', '1.2.9', VersionChange::Minor],
    'unparseable' => ['banana', '1.2.3', VersionChange::Major],
]);

it('colours major changes red and smaller changes amber by default', function (): void {
    expect(VersionChange::defaultTagColors())->toBe([
        'major' => VersionTagColor::Red->value,
        'minor' => VersionTagColor::Amber->value,
        'patch' => VersionTagColor::Amber->value,
    ]);
});
