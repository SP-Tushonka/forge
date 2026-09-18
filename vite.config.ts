import { copyFileSync, globSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite-plus';

/**
 * Minifies the hand-maintained JSON files under resources/json and writes them, preserving their relative path, into
 * the local public disk (storage/app/public). The app:upload-assets command then mirrors these to Cloudflare R2 so they
 * are served from forge-static.sp-mod.com at a stable, unhashed URL. The JSON is validated and minified.
 */
function copyStaticJson() {
    const sourceDir = resolve('resources/json');
    const targetDir = resolve('storage/app/public');
    let logger;

    const writeFile = (absoluteSource) => {
        const relativePath = relative(sourceDir, absoluteSource);
        const minified = JSON.stringify(JSON.parse(readFileSync(absoluteSource, 'utf8')));
        const target = join(targetDir, relativePath);
        mkdirSync(dirname(target), { recursive: true });
        writeFileSync(target, minified);
        logger?.info(`[copy-static-json] processed ${relativePath}`, { timestamp: true });
    };

    const writeAll = () => {
        for (const source of globSync('resources/json/**/*.json')) {
            writeFile(resolve(source));
        }
    };

    return {
        name: 'forge:copy-static-json',
        configResolved(config) {
            logger = config.logger;
        },
        buildStart() {
            writeAll();
        },
        configureServer(server) {
            writeAll();
            server.watcher.add(sourceDir);
            server.watcher.on('all', (_event, file) => {
                const absolute = resolve(file);
                if (absolute.startsWith(sourceDir + sep) && absolute.endsWith('.json')) {
                    try {
                        writeFile(absolute);
                    } catch (error) {
                        logger?.warn(`[copy-static-json] failed to process ${relative(sourceDir, absolute)}: ${error}`);
                    }
                }
            });
        },
    };
}

/**
 * Copies the Twemoji SVG set out of @discordapp/twemoji into public/vendor/twemoji/svg. The app:upload-assets command
 * uploads all of public/vendor to Cloudflare R2, so the emoji reach forge-static.sp-mod.com without a deploy change.
 * The directory is generated and gitignored, the same arrangement public/vendor/horizon already uses.
 */
function copyTwemoji() {
    const sourceDir = resolve('node_modules/@discordapp/twemoji/dist/svg');
    const targetDir = resolve('public/vendor/twemoji/svg');
    let logger;

    const writeAll = () => {
        mkdirSync(targetDir, { recursive: true });
        let count = 0;
        for (const name of readdirSync(sourceDir)) {
            if (!name.endsWith('.svg')) {
                continue;
            }
            copyFileSync(join(sourceDir, name), join(targetDir, name));
            count++;
        }
        logger?.info(`[copy-twemoji] copied ${count} svg files`, { timestamp: true });
    };

    return {
        name: 'forge:copy-twemoji',
        configResolved(config) {
            logger = config.logger;
        },
        buildStart() {
            writeAll();
        },
        configureServer() {
            writeAll();
        },
    };
}

export default defineConfig({
    fmt: {
        printWidth: 120,
        tabWidth: 4,
        useTabs: false,
        semi: true,
        singleQuote: true,
        singleAttributePerLine: true,
        sortTailwindcss: {
            functions: ['clsx', 'cn'],
            stylesheet: 'resources/css/app.css',
        },
        sortImports: {
            groups: ['builtin', 'external', 'internal', 'parent', 'sibling', 'index'],
            newlinesBetween: false,
        },
        ignorePatterns: ['resources/views/mail/*', 'resources/markdown/*'],
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', ...globSync('resources/{images,video}/**/*')],
            refresh: true,
        }),
        tailwindcss(),
        copyStaticJson(),
        copyTwemoji(),
    ],
});
