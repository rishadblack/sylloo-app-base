import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// The shared tests import the shared code as `@desktop-base/...`: here this folder's src/, in an app its synced copy.
export default defineConfig({
    resolve: {
        alias: { '@desktop-base': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    test: {
        include: ['tests/**/*.test.ts', 'sync.test.mjs'],
    },
});
