/**
 * MoodleManagement — Certificate PDF
 * Opens the PDF endpoint for the current certificate in a new tab.
 *
 * @since 2.0 F3.5 — translated alerts via window.FS_I18N (injected
 *                   by server-side Twig). Falls back to English if
 *                   the key is missing so the script works in any
 *                   context.
 */
function downloadCertificatePdf() {
    var codeInput = document.querySelector('input[name="code"]');
    if (!codeInput || !codeInput.value) {
        var i18n = (window.FS_I18N && window.FS_I18N['certificate-not-saved-yet'])
            ? window.FS_I18N['certificate-not-saved-yet']
            : 'Certificate not saved yet.';
        alert(i18n);
        return;
    }
    window.open('MoodleCertificatePdf?code=' + encodeURIComponent(codeInput.value), '_blank');
}
