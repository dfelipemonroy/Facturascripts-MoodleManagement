/**
 * MoodleManagement — Certificate PDF
 * Opens the PDF endpoint for the current certificate in a new tab.
 */
function downloadCertificatePdf() {
    var codeInput = document.querySelector('input[name="code"]');
    if (!codeInput || !codeInput.value) {
        alert('Certificate not saved yet.');
        return;
    }
    window.open('MoodleCertificatePdf?code=' + encodeURIComponent(codeInput.value), '_blank');
}
