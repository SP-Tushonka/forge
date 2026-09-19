<?php

declare(strict_types=1);

use App\Console\Commands\UploadAssetsCommand;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToListContents;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('r2');

    // A small public directory stands in for the real build and vendor output.
    $this->publicPath = storage_path('framework/testing/upload-assets-'.Str::lower(Str::random(8)));
    File::ensureDirectoryExists($this->publicPath.'/build/assets');
    File::ensureDirectoryExists($this->publicPath.'/vendor/twemoji/svg');
    File::put($this->publicPath.'/build/assets/app.js', 'console.log(1);');
    File::put($this->publicPath.'/vendor/twemoji/svg/1f600.svg', '<svg>smile</svg>');
    File::put($this->publicPath.'/vendor/twemoji/svg/1f601.svg', '<svg>grin</svg>');
    $this->app->usePublicPath($this->publicPath);
});

afterEach(function (): void {
    File::deleteDirectory($this->publicPath);
});

it('uploads the ignored-updates static file to R2', function (): void {
    Storage::disk('public')->put('check-mods/ignored-updates.json', '{"schemaVersion":1,"ignored":[]}');

    $this->artisan(UploadAssetsCommand::class)
        ->expectsOutputToContain('Uploading static file to: check-mods/ignored-updates.json')
        ->assertSuccessful();

    Storage::disk('r2')->assertExists('check-mods/ignored-updates.json');
    expect(Storage::disk('r2')->get('check-mods/ignored-updates.json'))
        ->toBe('{"schemaVersion":1,"ignored":[]}');
});

it('skips the static file when it is missing locally', function (): void {
    $this->artisan(UploadAssetsCommand::class)
        ->expectsOutputToContain('Skipping missing static file: check-mods/ignored-updates.json')
        ->assertSuccessful();

    Storage::disk('r2')->assertMissing('check-mods/ignored-updates.json');
});

it('uploads build and vendor files that are not on R2 yet', function (): void {
    $this->artisan(UploadAssetsCommand::class)
        ->expectsOutputToContain('build: 1 uploaded, 0 unchanged')
        ->expectsOutputToContain('vendor: 2 uploaded, 0 unchanged')
        ->assertSuccessful();

    Storage::disk('r2')->assertExists(['build/assets/app.js', 'vendor/twemoji/svg/1f600.svg', 'vendor/twemoji/svg/1f601.svg']);
});

it('skips files already on R2 with the same content', function (): void {
    Storage::disk('r2')->put('vendor/twemoji/svg/1f600.svg', '<svg>smile</svg>');
    Storage::disk('r2')->put('vendor/twemoji/svg/1f601.svg', '<svg>grin</svg>');

    $this->artisan(UploadAssetsCommand::class)
        ->expectsOutputToContain('vendor: 0 uploaded, 2 unchanged')
        ->doesntExpectOutputToContain('Uploading asset to: vendor/twemoji/svg/1f600.svg')
        ->assertSuccessful();
});

it('uploads a file again when its content changed', function (): void {
    Storage::disk('r2')->put('vendor/twemoji/svg/1f600.svg', '<svg>old</svg>');
    Storage::disk('r2')->put('vendor/twemoji/svg/1f601.svg', '<svg>grin</svg>');

    $this->artisan(UploadAssetsCommand::class)
        ->expectsOutputToContain('vendor: 1 uploaded, 1 unchanged')
        ->assertSuccessful();

    expect(Storage::disk('r2')->get('vendor/twemoji/svg/1f600.svg'))->toBe('<svg>smile</svg>');
});

it('uploads everything when R2 cannot be listed', function (): void {
    $root = $this->publicPath.'-r2';
    $adapter = new class($root) extends LocalFilesystemAdapter
    {
        public function listContents(string $path, bool $deep): iterable
        {
            throw UnableToListContents::atLocation($path, $deep, new RuntimeException('listing unavailable'));
        }
    };
    Storage::set('r2', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root]));
    Storage::disk('r2')->put('vendor/twemoji/svg/1f600.svg', '<svg>smile</svg>');

    $this->artisan(UploadAssetsCommand::class)
        ->expectsOutputToContain('Could not list vendor on R2')
        ->expectsOutputToContain('vendor: 2 uploaded, 0 unchanged')
        ->assertSuccessful();

    File::deleteDirectory($root);
});
