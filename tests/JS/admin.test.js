/**
 * Unit tests for admin.js.
 *
 * Tests clipboard functionality and Select2/UTM field initialization.
 * The script is an IIFE that auto-executes on load.
 *
 * @package Tests/JS
 * @since   1.0.0
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const scriptPath = resolve(__dirname, '../../js/admin.js');
const scriptSource = readFileSync(scriptPath, 'utf-8');

/**
 * Evaluates the IIFE script in the current global context.
 */
function loadScript() {
    const fn = new Function(scriptSource);
    fn();
}

/**
 * Mocks navigator.clipboard with a fresh writeText spy.
 * Returns the spy.
 */
function mockClipboard() {
    const writeTextSpy = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: writeTextSpy },
        writable: true,
        configurable: true,
    });
    return writeTextSpy;
}

describe('admin.js', () => {

    beforeEach(() => {
        // Clean DOM.
        document.body.innerHTML = '';

        // Remove jQuery/kntntAdAttrAdmin globals.
        delete window.jQuery;
        delete window.kntntAdAttrAdmin;

        // Ensure DOM is ready so init runs immediately.
        Object.defineProperty(document, 'readyState', {
            value: 'complete',
            writable: true,
            configurable: true,
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    // ─── Clipboard ───

    describe('clipboard', () => {

        /** Creates a copy element in the DOM. */
        function createCopyElement(text = 'https://example.com/ad/abc123') {
            const el = document.createElement('code');
            el.className = 'kntnt-ad-attr-copy';
            el.setAttribute('role', 'button');
            el.setAttribute('tabindex', '0');
            el.setAttribute('data-clipboard-text', text);
            el.textContent = text;
            document.body.appendChild(el);
            return el;
        }

        it('copies data-clipboard-text on click', async () => {
            const el = createCopyElement('https://example.com/ad/test');
            const writeTextSpy = mockClipboard();

            loadScript();
            el.click();

            await vi.waitFor(() => {
                expect(writeTextSpy).toHaveBeenCalledWith('https://example.com/ad/test');
            });
        });

        it('copies on Enter keypress', async () => {
            const el = createCopyElement('https://example.com/ad/enter');
            const writeTextSpy = mockClipboard();

            loadScript();
            el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

            await vi.waitFor(() => {
                expect(writeTextSpy).toHaveBeenCalledWith('https://example.com/ad/enter');
            });
        });

        it('does not copy on other key presses', () => {
            const el = createCopyElement('https://example.com/ad/space');
            const writeTextSpy = mockClipboard();

            loadScript();
            el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Space', bubbles: true }));
            el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));

            expect(writeTextSpy).not.toHaveBeenCalled();
        });

        it('adds copied class on success', async () => {
            const el = createCopyElement('https://example.com/ad/copied');
            mockClipboard();

            loadScript();
            el.click();

            await vi.waitFor(() => {
                expect(el.classList.contains('copied')).toBe(true);
            });
        });

        it('removes copied class after 1.5s', async () => {
            vi.useFakeTimers();

            const el = createCopyElement('https://example.com/ad/timeout');
            mockClipboard();

            loadScript();
            el.click();

            // Flush the resolved-promise microtask so .then() fires.
            await vi.advanceTimersByTimeAsync(0);

            expect(el.classList.contains('copied')).toBe(true);

            // Advance past the 1500ms timeout.
            await vi.advanceTimersByTimeAsync(1500);

            expect(el.classList.contains('copied')).toBe(false);

            vi.useRealTimers();
        });

        it('skips copy when data-clipboard-text is empty', () => {
            const el = createCopyElement('');
            const writeTextSpy = mockClipboard();

            loadScript();
            el.click();

            expect(writeTextSpy).not.toHaveBeenCalled();
        });

    });

    // ─── Select2 guards ───

    describe('Select2 initialization', () => {

        it('returns early if jQuery is undefined', () => {
            expect(() => loadScript()).not.toThrow();
        });

        it('returns early if kntntAdAttrAdmin is undefined', () => {
            window.jQuery = Object.assign(
                () => ({ length: 0, select2: vi.fn(), on: vi.fn(), val: vi.fn(), find: vi.fn() }),
                { fn: { select2: vi.fn() } },
            );

            expect(() => loadScript()).not.toThrow();
        });

    });

    // ─── UTM auto-fill ───

    describe('UTM auto-fill', () => {

        /**
         * Creates a minimal jQuery mock that supports the auto-fill flow.
         *
         * The admin.js change handler does `$(this).val()` where `this`
         * is the source DOM element. We track which source value to return
         * and handle both string selectors and DOM element arguments.
         */
        function setupJQueryMock({ mediumValue = '', sourceVal = 'google' } = {}) {
            const select2Spy = vi.fn();
            const changeHandlers = {};
            let currentMediumVal = mediumValue;

            // Track the "source element" to recognize $(this) calls.
            const sourceElement = {};

            const jQueryFn = (selector) => {
                // $(this) inside the change handler — `this` is sourceElement.
                if (selector === sourceElement) {
                    return { val: () => sourceVal };
                }
                if (selector === '#kntnt-ad-attr-target-post') {
                    return { length: 1, select2: select2Spy };
                }
                if (selector === '.kntnt-ad-attr-select2-tags') {
                    return { length: 1, select2: select2Spy };
                }
                if (selector === '#kntnt-ad-attr-utm_source') {
                    return {
                        on: (event, handler) => { changeHandlers.source = handler; },
                    };
                }
                if (selector === '#kntnt-ad-attr-utm_medium') {
                    return {
                        val: (...args) => {
                            if (args.length === 0) return currentMediumVal;
                            currentMediumVal = args[0];
                            return { trigger: vi.fn() };
                        },
                        find: (sel) => ({
                            length: sel.includes('cpc') ? 1 : 0,
                        }),
                    };
                }
                return { length: 0, select2: vi.fn(), on: vi.fn(), val: vi.fn(), find: vi.fn() };
            };

            jQueryFn.fn = { select2: vi.fn() };

            return {
                jQueryFn,
                changeHandlers,
                sourceElement,
                getMediumVal: () => currentMediumVal,
            };
        }

        it('auto-fills medium when source is selected and medium is empty', () => {
            const { jQueryFn, changeHandlers, sourceElement, getMediumVal } = setupJQueryMock({
                mediumValue: '',
                sourceVal: 'google',
            });

            window.jQuery = jQueryFn;
            window.kntntAdAttrAdmin = {
                searchUrl: 'https://example.com/search',
                nonce: 'nonce',
                utmSources: { google: 'cpc', facebook: 'cpm' },
            };

            loadScript();

            // Trigger source change with `this` = sourceElement.
            expect(changeHandlers.source).toBeDefined();
            changeHandlers.source.call(sourceElement);

            expect(getMediumVal()).toBe('cpc');
        });

        it('does not overwrite medium if already has value', () => {
            const { jQueryFn, changeHandlers, sourceElement, getMediumVal } = setupJQueryMock({
                mediumValue: 'existing-medium',
                sourceVal: 'google',
            });

            window.jQuery = jQueryFn;
            window.kntntAdAttrAdmin = {
                searchUrl: 'https://example.com/search',
                nonce: 'nonce',
                utmSources: { google: 'cpc' },
            };

            loadScript();

            changeHandlers.source.call(sourceElement);

            expect(getMediumVal()).toBe('existing-medium');
        });

    });

});
