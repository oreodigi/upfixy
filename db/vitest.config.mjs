import { defineConfig } from 'vitest/config';
// Every file resets the same test database, so files must not run in parallel.
export default defineConfig({ test: { fileParallelism: false, testTimeout: 20000, hookTimeout: 30000 } });
