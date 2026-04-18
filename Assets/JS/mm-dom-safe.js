/**
 * Small DOM-writing helpers that surface the safe, text-only path
 * first. Addresses audit FE-04 (2026-04-17): callers used to reach
 * for `element.innerHTML = …` by default; this module exposes
 * `mmSetText` and `mmClearAndAppend` so the opt-in decision to emit
 * markup is explicit (and grep-able during code review).
 *
 * Loaded alongside `mm-actions.js`; no dependency on jQuery.
 *
 * @since 2.0 — FE-04
 */
(function () {
    'use strict';

    /**
     * Replace the text content of `el` with `text`. Never interprets
     * HTML; safe for Moodle-sourced strings that may contain `<` etc.
     */
    function mmSetText(el, text) {
        if (!el) return;
        el.textContent = (text == null) ? '' : String(text);
    }

    /**
     * Detach every child of `el` and append the provided node(s).
     * Prefer this over rebuilding an HTML string when the required
     * structure is dynamic: each node is created via
     * `document.createElement` so the browser's parser never runs
     * on untrusted data.
     *
     * @param {Element} el
     * @param {Node|Node[]} nodes
     */
    function mmClearAndAppend(el, nodes) {
        if (!el) return;
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        var arr = Array.isArray(nodes) ? nodes : [nodes];
        for (var i = 0; i < arr.length; i++) {
            if (arr[i]) el.appendChild(arr[i]);
        }
    }

    /**
     * `document.createElement` wrapper with optional text content and
     * attribute map. Attributes are set via `setAttribute` so no
     * special-case (onclick etc.) is interpreted.
     */
    function mmCreate(tag, text, attrs) {
        var el = document.createElement(tag);
        if (text != null) el.textContent = String(text);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                el.setAttribute(k, attrs[k]);
            });
        }
        return el;
    }

    window.mmSetText = mmSetText;
    window.mmClearAndAppend = mmClearAndAppend;
    window.mmCreate = mmCreate;
})();
