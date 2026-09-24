document.addEventListener('DOMContentLoaded', () => {
    const reportType = document.querySelector('#dashboard-report-type');
    const reportLink = document.querySelector('#dashboard-report-link');
    if (!reportType || !reportLink) {
        return;
    }

    reportType.addEventListener('change', () => {
        reportLink.href = `${reportLink.dataset.baseUrl}?report=${encodeURIComponent(reportType.value)}`;
    });
    reportLink.dataset.baseUrl = reportLink.href.split('?')[0];
});
document.addEventListener('DOMContentLoaded', () => {
    // Reserved for later dashboard behaviour. Bootstrap handles navigation.
});
document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.querySelector('.profile-file-input');
    const fileNameLabel = document.querySelector('.profile-file-name');
    const uploadForm = document.querySelector('.profile-upload-form');
    if (!fileInput || !uploadForm) {
        return;
    }

    fileInput.addEventListener('change', () => {
        if (fileNameLabel) {
            fileNameLabel.textContent = fileInput.files?.[0]?.name ?? '';
        }
    });

    uploadForm.addEventListener('submit', (event) => {
        if (!fileInput.files || fileInput.files.length === 0) {
            event.preventDefault();
            if (fileNameLabel) {
                fileNameLabel.textContent = 'Please choose a picture first.';
            }
        }
    });
});

