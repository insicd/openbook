(() => {
    const page = document.querySelector('[data-report-page]');
    const form = page?.querySelector('#report-action');
    if (!form) return;

    const button = form.querySelector('[data-report-submit]');
    const explanation = form.querySelector('[data-report-selection]');
    const update = () => {
        const count = page.querySelectorAll('[data-report-delete]:checked').length;
        button.textContent = count ? form.dataset.deleteLabel : form.dataset.closeLabel;
        button.classList.toggle('ob-report-destructive', count > 0);
        explanation.textContent = count
            ? form.dataset.selectedHelp.replace(':count', String(count))
            : form.dataset.emptyHelp;
        button.title = explanation.textContent;
    };
    page.addEventListener('change', event => {
        if (event.target.matches('[data-report-delete]')) update();
    });
    // Browsers can restore checkbox state when navigating back to the case.
    window.addEventListener('pageshow', update);
    update();
})();
