import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['tests/JS/**/*.test.js'],
        globals: true,
        coverage: {
            provider: 'v8',
            include: ['js/**/*.js'],
            reporter: ['text', 'lcov'],
            reportsDirectory: 'coverage/js',
        },
    },
});
