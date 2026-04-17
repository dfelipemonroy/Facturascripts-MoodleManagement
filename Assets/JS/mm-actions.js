/*!
 * MoodleManagement — event delegation layer.
 *
 * Lets Twig templates drop inline `onclick="fn(arg)"` handlers in
 * favour of `data-mm-*` attributes that are picked up by a single
 * bubbling listener on <body>. The same global function names
 * keep working, so moving to this system is purely an attribute
 * rewrite on the Twig side.
 *
 * Supported attributes on the element that carries the click:
 *
 *   data-mm-call         Function to invoke (resolved against window).
 *   data-mm-args         JSON-encoded array of arguments. Parsed once
 *                        when the listener fires.
 *   data-mm-preventdefault
 *                        '1' to call event.preventDefault() (for <a>).
 *   data-mm-stop         '1' to stop propagation.
 *   data-mm-confirm      Native confirm() text. If the user cancels,
 *                        the event is prevented. Works on any
 *                        clickable element (button, link) and on
 *                        form submits (attach to <form> or the
 *                        submit <button>).
 *   data-mm-change-call  As `data-mm-call` but fires on `change`
 *                        instead of `click` (for <select>/<input>).
 *   data-mm-input-call   As `data-mm-call` but fires on `input`
 *                        (debounced by the called function, not here).
 *   data-mm-enter-submit '1' on a <textarea> = pressing Enter
 *                        (without Shift) submits the enclosing form.
 *
 * Example:
 *
 *   BEFORE:
 *     <button onclick="bulkAction('cm-show')">…</button>
 *   AFTER:
 *     <button data-mm-call="bulkAction" data-mm-args='["cm-show"]'>…</button>
 *
 * The JSON blob is HTML-escaped by Twig automatically, so Moodle-
 * sourced values are safe for reinsertion into the DOM.
 *
 * This layer is a strict CSP ally: once every Twig migrates, CSP
 * can drop `'unsafe-inline'` from script-src entirely (F2.11 will
 * tighten then).
 *
 * @since 2.0 — V2.0-ACTION-PLAN F3.2 · §3.6
 */
(function () {
    'use strict';

    function resolveTarget(name) {
        if (!name || typeof name !== 'string') return null;
        // Walk dotted paths: "MoodleManagement.actions.doThing"
        var parts = name.split('.');
        var node = window;
        for (var i = 0; i < parts.length; i++) {
            if (node == null) return null;
            node = node[parts[i]];
        }
        return typeof node === 'function' ? node : null;
    }

    function parseArgs(raw) {
        if (!raw) return [];
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [parsed];
        } catch (e) {
            // Fallback: treat as single string argument.
            return [raw];
        }
    }

    function closestWithDataset(start, keys) {
        var el = start;
        while (el && el !== document.body) {
            if (el.dataset) {
                for (var i = 0; i < keys.length; i++) {
                    if (el.dataset[keys[i]] !== undefined) {
                        return el;
                    }
                }
            }
            el = el.parentNode;
        }
        return null;
    }

    function handleConfirm(event) {
        var el = closestWithDataset(event.target, ['mmConfirm']);
        if (!el) return;
        var msg = el.dataset.mmConfirm;
        if (msg && !window.confirm(msg)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }

    function handleClick(event) {
        // Confirm first — may cancel the whole interaction.
        handleConfirm(event);
        if (event.defaultPrevented) return;

        var el = closestWithDataset(event.target, ['mmCall']);
        if (!el) return;

        var fn = resolveTarget(el.dataset.mmCall);
        if (!fn) {
            if (window.console && typeof window.console.warn === 'function') {
                console.warn('[mm-actions] function not found:', el.dataset.mmCall);
            }
            return;
        }

        var args = parseArgs(el.dataset.mmArgs || '');

        if (el.dataset.mmPreventdefault === '1') {
            event.preventDefault();
        }
        if (el.dataset.mmStop === '1') {
            event.stopPropagation();
        }

        try {
            fn.apply(el, args);
        } catch (err) {
            if (window.console && typeof window.console.error === 'function') {
                console.error('[mm-actions] handler threw:', err);
            }
        }
    }

    function invokeFromEvent(event, datasetKey) {
        var el = closestWithDataset(event.target, [datasetKey]);
        if (!el) return;
        var fn = resolveTarget(el.dataset[datasetKey]);
        if (!fn) return;
        var argsAttr = el.dataset[datasetKey.replace('Call', 'Args')] || '';
        var args = parseArgs(argsAttr);
        try {
            fn.apply(el, args);
        } catch (err) {
            if (window.console && typeof window.console.error === 'function') {
                console.error('[mm-actions] ' + datasetKey + ' handler threw:', err);
            }
        }
    }

    function handleChange(event) {
        invokeFromEvent(event, 'mmChangeCall');
    }

    function handleInput(event) {
        invokeFromEvent(event, 'mmInputCall');
    }

    function handleKeydown(event) {
        // Enter-submits-form behaviour for textareas.
        var target = event.target;
        if (!target || !target.dataset || target.dataset.mmEnterSubmit !== '1') {
            return;
        }
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            if (target.form && typeof target.form.submit === 'function') {
                target.form.submit();
            }
        }
    }

    function handleSubmit(event) {
        // `confirm` on a <form> or on a submit <button>: if submit
        // was triggered by a click that already asked confirm via
        // handleConfirm, we're done. But native form submit (Enter
        // key in input) skips the click phase, so re-check here.
        handleConfirm(event);
    }

    function init() {
        document.body.addEventListener('click', handleClick, false);
        document.body.addEventListener('change', handleChange, false);
        document.body.addEventListener('input', handleInput, false);
        document.body.addEventListener('keydown', handleKeydown, false);
        document.body.addEventListener('submit', handleSubmit, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
