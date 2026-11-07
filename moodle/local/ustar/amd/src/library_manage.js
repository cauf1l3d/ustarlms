/** Question editing only; PHP writers validate every submitted value and permission. */
export const init = () => {
    document.querySelector('[data-library-error]')?.focus();
    document.querySelectorAll('[data-library-editor]').forEach(form => {
        let dirty = form.dataset.libraryPending === '1';
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('change', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', event => {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        const list = form.querySelector('[data-library-questions]');
        if (!list) {
            return;
        }
        const add = form.querySelector('[data-library-add-question]');
        const template = form.querySelector('[data-library-question-template]');
        const status = form.querySelector('[data-library-question-status]');
        const rows = () => [...list.querySelectorAll('[data-library-question]')];
        let nextKey = Math.max(-1, ...rows().map(row => Number(row.dataset.libraryQuestion)).filter(Number.isFinite)) + 1;
        const update = message => {
            const all = rows();
            all.forEach((row, index) => {
                row.querySelector('[data-library-number]').textContent = 'Вопрос ' + (index + 1);
                row.querySelector('[data-library-question-action="up"]').disabled = index === 0;
                row.querySelector('[data-library-question-action="down"]').disabled = index === all.length - 1;
            });
            add.disabled = all.length >= 100;
            status.textContent = (message ? message + ' ' : '') + 'Блоков вопросов: ' + all.length + ' из 100.';
        };
        add.addEventListener('click', () => {
            if (rows().length >= 100) {
                return;
            }
            const row = template.content.firstElementChild.cloneNode(true);
            const key = String(nextKey++);
            row.dataset.libraryQuestion = key;
            row.querySelectorAll('[name]').forEach(field => {
                field.name = field.name.replace('[__key__]', '[' + key + ']');
            });
            list.append(row);
            dirty = true;
            update('Вопрос добавлен.');
            row.querySelector('textarea').focus();
        });
        list.addEventListener('click', event => {
            const button = event.target.closest('[data-library-question-action]');
            if (!button) {
                return;
            }
            const row = button.closest('[data-library-question]');
            const action = button.dataset.libraryQuestionAction;
            if (action === 'remove') {
                const focus = row.nextElementSibling || row.previousElementSibling;
                row.remove();
                (focus?.querySelector('textarea') || add).focus();
            } else if (action === 'up' && row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
            } else if (action === 'down' && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
            } else {
                return;
            }
            dirty = true;
            update(action === 'remove' ? 'Вопрос удалён.' : 'Порядок вопросов изменён.');
            if (action !== 'remove') {
                (button.disabled ? row.querySelector('textarea') : button).focus();
            }
        });
        update();
    });
};
