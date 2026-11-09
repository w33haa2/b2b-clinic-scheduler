import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

// Set by the "vite" service in compose.yaml. The dev server listens on all
// interfaces inside the container, while the browser reaches it via localhost.
const dockerHmrHost = process.env.VITE_HMR_HOST;
const dockerServer = dockerHmrHost
    ? {
          host: '0.0.0.0',
          port: 5173,
          strictPort: true,
          // Lets the PHP containers reach the Inertia SSR endpoint at http://vite:5173.
          allowedHosts: ['vite'],
          hmr: {
              host: dockerHmrHost,
              clientPort: Number(process.env.VITE_HMR_CLIENT_PORT ?? 5173),
          },
      }
    : {};

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                // Modern clinical & SaaS UI sans: warm humanist geometry, clear 1/I/l disambiguation.
                bunny('Plus Jakarta Sans', {
                    weights: [400, 500, 600, 700],
                    fallbacks: ['ui-sans-serif', 'system-ui', 'sans-serif'],
                }),
                // Warm soft-serif display face for landing headlines & editorial accents.
                bunny('Fraunces', {
                    weights: [400, 500, 600],
                    styles: ['normal', 'italic'],
                    preload: false,
                    fallbacks: ['Georgia', 'ui-serif', 'serif'],
                }),
                // High-legibility tabular monospace for clinic times, amounts, and invoice codes.
                bunny('JetBrains Mono', {
                    weights: [400, 500, 600],
                    preload: false,
                    fallbacks: ['ui-monospace', 'SFMono-Regular', 'monospace'],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        ...dockerServer,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
