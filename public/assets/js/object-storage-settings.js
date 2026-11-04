(() => {
    const form = document.getElementById('object-storage-settings');
    if (!form) return;
    const provider = form.querySelector('#provider');
    const direct = form.querySelector('#direct');
    const update = () => {
        const fields = {
            account_id: ['cloudflareR2'],
            region: ['amazonS3', 'digitalOcean', 'ovhcloud', 'other'],
            endpoint: ['ovhcloud', 'other'],
            path_style: ['other'],
        };
        form.querySelectorAll('[data-provider-field]').forEach(container => {
            const visible = fields[container.dataset.providerField].includes(provider.value);
            container.hidden = !visible;
            container.querySelectorAll('input').forEach(input => { input.disabled = !visible; });
        });
        document.getElementById('public-url-field').hidden = !direct.checked;
    };
    provider.addEventListener('change', update);
    direct.addEventListener('change', update);
    update();
})();
