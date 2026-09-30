/* Progressive enhancement only: permissions, versions and persistence stay server-side. */
(function () {
    'use strict';
    function init() {
        const root = document.querySelector('.u-workspace');
        if (!root) { return; }
        const type = root.querySelector('select[name="kind"]');
        function updateKind() {
            root.querySelectorAll('[data-kind]').forEach(el => {
                el.hidden = el.dataset.kind !== type.value;
                el.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = el.hidden; });
            });
            const repeat = root.querySelector('[data-repeat-section]');
            if (repeat) {
                repeat.hidden = type.value === 'retraining';
                repeat.querySelectorAll('input,textarea').forEach(input => { input.disabled = repeat.hidden; });
            }
        }
        if (type) { type.addEventListener('change', updateKind); updateKind(); }
        root.addEventListener('click', event => {
            const button = event.target.closest('button');
            if (!button) { return; }
            if (button.hasAttribute('data-add-field') || button.hasAttribute('data-add-level')) {
                const isField = button.hasAttribute('data-add-field');
                const container = root.querySelector(isField ? '[data-builder]' : '[data-rule-levels]');
                const maximum = isField ? 40 : 5;
                if (container.children.length >= maximum) { return; }
                const template = root.querySelector(isField ? '[data-field-template]' : '[data-level-template]');
                const fragment = template.content.cloneNode(true);
                const key = fragment.querySelector('input[name="fieldkey[]"]');
                if (key) { key.value = 'f_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10); }
                container.appendChild(fragment);
                container.lastElementChild.querySelector('input:not([type="hidden"]),select').focus();
            }
            if (button.hasAttribute('data-remove-row')) {
                const row = button.closest('.uw-builder-row');
                if (row.parentElement.hasAttribute('data-builder') && row.parentElement.children.length <= 1) { return; }
                row.remove();
            }
            if (button.hasAttribute('data-move-up')) {
                const row = button.closest('.uw-builder-row');
                if (row.previousElementSibling) { row.parentElement.insertBefore(row, row.previousElementSibling); }
            }
        });
        root.querySelectorAll('input[type="file"][accept]').forEach(input => {
            const preview = input.closest('form').querySelector('[data-photo-preview]');
            let urls = [];
            input.addEventListener('change', () => {
                urls.forEach(url => URL.revokeObjectURL(url)); urls = []; preview.replaceChildren();
                Array.from(input.files).slice(0, 5).forEach(file => {
                    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { return; }
                    const img = document.createElement('img');
                    const url = URL.createObjectURL(file); urls.push(url); img.src = url; img.alt = file.name;
                    preview.appendChild(img);
                });
            });
            window.addEventListener('pagehide', () => urls.forEach(url => URL.revokeObjectURL(url)), {once: true});
        });
        const panel = root.querySelector('#uw-editor') || root.querySelector('#uw-detail');
        if (panel) { panel.scrollIntoView({block: 'start', behavior: 'auto'}); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
