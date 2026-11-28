document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) {
        return;
    }

    const link = event.target.closest('[data-peertube-play]');
    const container = link?.closest('[data-peertube-player]');
    const template = container?.querySelector('[data-peertube-frame]');
    if (!template) {
        return;
    }

    event.preventDefault();
    const frame = template.content.cloneNode(true);
    const iframe = frame.querySelector('iframe');
    link.parentElement.replaceWith(frame);
    iframe?.focus();
});
