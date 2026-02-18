/**
 * Scaffold test to verify Vitest is working.
 *
 * This file can be removed once real JS tests are added.
 */

import { describe, it, expect } from 'vitest';

describe('Scaffold', () => {

    it('can run a basic test', () => {
        expect(true).toBe(true);
    });

    it('has DOM available via happy-dom', () => {
        const div = document.createElement('div');
        div.textContent = 'hello';
        expect(div.textContent).toBe('hello');
    });

});
