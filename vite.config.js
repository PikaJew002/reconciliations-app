import { existsSync, readdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { sentryVitePlugin } from '@sentry/vite-plugin';

function deleteBuildSourceMaps() {
    return {
        name: 'delete-build-source-maps',
        apply: 'build',
        closeBundle() {
            const directory = join(process.cwd(), 'public/build');

            if (!existsSync(directory)) {
                return;
            }

            const removeMaps = (dir) => {
                for (const entry of readdirSync(dir, { withFileTypes: true })) {
                    const path = join(dir, entry.name);

                    if (entry.isDirectory()) {
                        removeMaps(path);
                    } else if (entry.name.endsWith('.map')) {
                        rmSync(path);
                    }
                }
            };

            removeMaps(directory);
        },
    };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const sentryAuthToken = env.SENTRY_AUTH_TOKEN || process.env.SENTRY_AUTH_TOKEN || '';
    const sentryAuthTokenPresent = sentryAuthToken !== '';

    return {
        build: {
            sourcemap: 'hidden',
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
            sentryVitePlugin({
                org: 'aaron-eisenberg',
                project: 'reconciliations-vue',
                authToken: sentryAuthToken || undefined,
                disable: !sentryAuthTokenPresent,
                sourcemaps: {
                    filesToDeleteAfterUpload: ['./public/build/**/*.map'],
                },
            }),
            ...(sentryAuthTokenPresent ? [] : [deleteBuildSourceMaps()]),
        ],
        server: {
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
