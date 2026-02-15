/**
 * Admin page JavaScript.
 *
 * - Click-to-copy on tracking URLs in the list view.
 * - Select2 initialization on the target post selector (add/edit form).
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

(function() {
    'use strict';

    /**
     * Click-to-copy for tracking URLs.
     *
     * Uses the Clipboard API (available in all modern browsers over HTTPS).
     * Listens for click and Enter keypress on elements with data-clipboard-text.
     */
    function initClipboard() {
        document.querySelectorAll('.kntnt-ad-attr-copy').forEach(function(el) {
            function copyText() {
                var text = el.getAttribute('data-clipboard-text');
                if (!text) return;

                navigator.clipboard.writeText(text).then(function() {
                    el.classList.add('copied');
                    setTimeout(function() {
                        el.classList.remove('copied');
                    }, 1500);
                });
            }

            el.addEventListener('click', copyText);
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') copyText();
            });
        });
    }

    /**
     * Select2 initialization for the target post selector.
     *
     * Only runs when jQuery, select2, and the localized config are available
     * (i.e. on the add form view).
     */
    function initSelect2() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
        if (typeof kntntAdAttrAdmin === 'undefined') return;

        var $ = jQuery;
        var $select = $('#kntnt-ad-attr-target-post');
        if (!$select.length) return;

        $select.select2({
            placeholder: '— Search for a page or post —',
            minimumInputLength: 2,
            allowClear: true,
            ajax: {
                url: kntntAdAttrAdmin.searchUrl,
                dataType: 'json',
                delay: 300,
                data: function(params) {
                    return {
                        search: params.term,
                        _wpnonce: kntntAdAttrAdmin.nonce
                    };
                },
                processResults: function(data) {
                    return {
                        results: data.map(function(item) {
                            return {
                                id: item.id,
                                text: item.title + ' (' + item.type + ' #' + item.id + ')'
                            };
                        })
                    };
                },
                cache: true
            }
        });
    }

    /**
     * Select2 tags initialization for UTM source and medium dropdowns.
     *
     * Enables free-text entry alongside predefined options. When a source
     * is selected and the medium field is empty, auto-fills the medium
     * with the source's default value from kntntAdAttrAdmin.utmSources.
     */
    function initUtmFields() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
        if (typeof kntntAdAttrAdmin === 'undefined') return;

        var $ = jQuery;
        var $tags = $('.kntnt-ad-attr-select2-tags');
        if (!$tags.length) return;

        $tags.select2({
            tags: true,
            allowClear: true,
            placeholder: '— Select or type —'
        });

        // Auto-fill medium when a predefined source is selected.
        $('#kntnt-ad-attr-utm_source').on('change', function() {
            var $medium = $('#kntnt-ad-attr-utm_medium');
            if ($medium.val()) return;

            var sourceVal = $(this).val();
            var sources = kntntAdAttrAdmin.utmSources || {};
            var defaultMedium = sources[sourceVal];

            if (defaultMedium && $medium.find('option[value="' + defaultMedium + '"]').length) {
                $medium.val(defaultMedium).trigger('change');
            }
        });
    }

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initClipboard();
            initSelect2();
            initUtmFields();
        });
    } else {
        initClipboard();
        initSelect2();
        initUtmFields();
    }

})();
