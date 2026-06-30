/*!
 * MoodleManagement — WidgetCourseimage image-picker behaviour.
 *
 * Delegates on `change` events to radios inside a `.mm-img-picker`
 * container, so the card that wraps the selected image is
 * highlighted without any inline onchange handler.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F3.11 · §3.16
 */
(function () {
    'use strict';

    function refreshSelection(container) {
        if (!container) return;
        container.querySelectorAll('.mm-img-picker-card').forEach(function (card) {
            card.classList.remove('border-primary', 'border-2');
        });
        var checked = container.querySelector('input[type="radio"]:checked');
        if (!checked) return;
        var label = checked.closest('.mm-img-picker-label');
        if (!label) return;
        var card = label.querySelector('.mm-img-picker-card');
        if (card) {
            card.classList.add('border-primary', 'border-2');
        }
    }

    function onChange(event) {
        var target = event.target;
        if (!target || target.type !== 'radio') return;
        var container = target.closest('.mm-img-picker');
        refreshSelection(container);
    }

    function init() {
        document.addEventListener('change', onChange, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
