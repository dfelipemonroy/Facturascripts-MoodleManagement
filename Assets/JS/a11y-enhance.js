/*!
 * Accessibility enhancement for MoodleManagement plugin.
 *
 * Mirrors `title` to `aria-label` on icon-only buttons / links
 * that lack an explicit aria-label, so screen readers always
 * announce a name. Runs once on DOMContentLoaded and also after
 * HTMX-style dynamic content insertions via MutationObserver.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F1.7 · §3.17
 */
(function () {
    'use strict';

    /**
     * True if the element contains no visible text (only nested icon nodes).
     * Checks against `<i>`, `<span class="sr-only">`, comment nodes.
     */
    function isIconOnly(el) {
        // Clone, strip <i>/<svg>/sr-only, and check if there's remaining text
        const clone = el.cloneNode(true);
        clone.querySelectorAll('i, svg, .sr-only, .visually-hidden').forEach(n => n.remove());
        return clone.textContent.trim().length === 0;
    }

    function enhance(root) {
        const scope = root || document;
        const candidates = scope.querySelectorAll(
            'button[title]:not([aria-label]), a[title]:not([aria-label])'
        );
        candidates.forEach(el => {
            if (isIconOnly(el)) {
                const label = (el.getAttribute('title') || '').trim();
                if (label) {
                    el.setAttribute('aria-label', label);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => enhance());
    } else {
        enhance();
    }

    // Observe dynamically inserted content (e.g. modals, wizard steps).
    if (typeof MutationObserver === 'function') {
        const observer = new MutationObserver(mutations => {
            for (const m of mutations) {
                m.addedNodes.forEach(node => {
                    if (node.nodeType === 1 /* ELEMENT_NODE */) {
                        enhance(node);
                    }
                });
            }
        });
        observer.observe(document.body || document.documentElement, {
            childList: true,
            subtree: true,
        });
    }
})();
