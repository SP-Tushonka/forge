<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Throwable;

#[Description('Uploads assets to Cloudflare R2')]
#[Signature('app:upload-assets')]
final class UploadAssetsCommand extends Command
{
    /**
     * This command uploads the Vite build assets to Cloudflare R2. Typically, this will be run after the assets have
     * been built and the application is ready to deploy from within the production environment build process.
     */
    public function handle(): void
    {
        $this->publishDirectory('build');
        $this->publishDirectory('vendor');
        $this->publishStaticFiles();
    }

    /**
     * Mirrors a public directory to R2, skipping files whose content is already there. vendor/ alone holds thousands of
     * Twemoji SVGs that only change with the package version, so one listing replaces thousands of redundant uploads.
     */
    private function publishDirectory(string $directory): void
    {
        $this->info(sprintf('Publishing %s assets...', $directory));

        $remote = $this->remoteChecksums($directory);
        $uploaded = 0;
        $unchanged = 0;

        foreach (File::allFiles(public_path($directory)) as $asset) {
            $path = $directory.'/'.str_replace('\\', '/', $asset->getRelativePathname());

            if (($remote[$path] ?? null) === md5_file($asset->getPathname())) {
                $unchanged++;

                continue;
            }

            $this->info('Uploading asset to: '.$path);
            Storage::disk('r2')->put($path, $asset->getContents());
            $uploaded++;
        }

        $this->info(sprintf('%s: %d uploaded, %d unchanged', $directory, $uploaded, $unchanged));
    }

    /**
     * The MD5 of every file already on R2 under the directory, keyed by path. R2 reports a single-part upload's ETag as
     * the MD5 of its content; a multipart ETag contains a dash, never matches, and that file is simply uploaded again.
     * Disks whose listing carries no ETag (the local fake in tests) are asked for the checksum instead. A failed listing
     * returns nothing, so everything is uploaded as before rather than the deploy failing.
     *
     * @return array<string, string>
     */
    private function remoteChecksums(string $directory): array
    {
        $disk = Storage::disk('r2');
        $checksums = [];

        try {
            foreach ($disk->getDriver()->listContents($directory, true) as $item) {
                if (! $item instanceof FileAttributes) {
                    continue;
                }

                $etag = $item->extraMetadata()['ETag'] ?? null;
                $checksum = is_string($etag) ? mb_trim($etag, '"') : $disk->checksum($item->path());

                if (is_string($checksum)) {
                    $checksums[$item->path()] = $checksum;
                }
            }
        } catch (Throwable $throwable) {
            $this->warn(sprintf('Could not list %s on R2 (%s); uploading everything.', $directory, $throwable->getMessage()));

            return [];
        }

        return $checksums;
    }

    /**
     * Publishes hand-maintained static files that live on the local public disk and need to mirror to R2. These are not
     * Vite build output, so they are pushed by their known relative path rather than discovered on disk.
     */
    private function publishStaticFiles(): void
    {
        $this->info('Publishing static files...');

        $staticFiles = [
            'check-mods/ignored-updates.json',
        ];

        foreach ($staticFiles as $staticFile) {
            $contents = Storage::disk('public')->get($staticFile);

            if ($contents === null) {
                $this->warn('Skipping missing static file: '.$staticFile);

                continue;
            }

            $this->info('Uploading static file to: '.$staticFile);
            Storage::disk('r2')->put($staticFile, $contents);
        }

        $this->info('Static files published successfully');
    }
}
