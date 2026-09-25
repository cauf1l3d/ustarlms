(function () {
    'use strict';
    function init() {
        const root = document.getElementById('ustar-authoring-questions');
        if (!root) { return; }
        let next = root.querySelectorAll('fieldset').length;
        function refresh() {
            root.querySelectorAll('fieldset').forEach((card, i) => {
                card.querySelector('[data-number]').textContent = i + 1;
                const essay = card.querySelector('[data-question-type]').value === 'essay';
                card.querySelector('[data-options]').hidden = essay;
                card.querySelectorAll('[data-options] input, [data-options] select').forEach(el => { el.disabled = essay; });
            });
        }
        document.getElementById('ustar-add-question').addEventListener('click', () => {
            if (root.querySelectorAll('fieldset').length >= 100) { return; }
            const html = document.getElementById('ustar-question-template').innerHTML.replaceAll('[99999]', '[' + next++ + ']');
            root.insertAdjacentHTML('beforeend', html); refresh();
            root.lastElementChild.querySelector('textarea').focus();
        });
        root.addEventListener('change', refresh);
        root.addEventListener('click', event => {
            if (event.target.matches('[data-remove-question]')) { event.target.closest('fieldset').remove(); refresh(); }
        });
        document.getElementById('ustar-bank-search').addEventListener('input', event => {
            const value = event.target.value.toLocaleLowerCase();
            document.querySelectorAll('[data-bank-row]').forEach(row => {row.hidden = !row.textContent.toLocaleLowerCase().includes(value);});
        });
        refresh();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
