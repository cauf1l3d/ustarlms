export const init = () => {
    const form = document.querySelector('[data-u-move-form]');
    if (!form) {
        return;
    }

    const status = document.querySelector('[data-u-move-status]');
    const workspace = document.querySelector('.u-materials-workspace');
    const submitMove = () => {
        form.setAttribute('aria-busy', 'true');
        if (workspace) {
            workspace.classList.add('is-moving');
        }
        if (status) {
            status.textContent = 'Перемещаем объект…';
        }
        form.submit();
    };

    document.querySelectorAll('.u-material-row-menu form').forEach((contextForm) => {
        const target = contextForm.querySelector('[data-u-context-move-target]');
        const submit = contextForm.querySelector('[data-u-context-move-submit]');
        if (!target || !submit) {
            return;
        }
        target.addEventListener('change', () => {
            submit.disabled = target.value === '';
        });
        contextForm.addEventListener('submit', () => {
            submit.disabled = true;
            submit.textContent = 'Перемещаем…';
            contextForm.setAttribute('aria-busy', 'true');
        });
    });

    let dragged = null;

    document.querySelectorAll('[data-u-drag-content]').forEach((row) => {
        row.addEventListener('dragstart', (event) => {
            dragged = {
                id: row.dataset.uDragContent,
                expected: row.dataset.uExpectedModified,
            };
            row.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', dragged.id);
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('is-dragging');
            document.querySelectorAll('.is-drop-target').forEach((target) => target.classList.remove('is-drop-target'));
            dragged = null;
        });
    });

    document.querySelectorAll('[data-u-drop-folder]').forEach((target) => {
        target.addEventListener('dragover', (event) => {
            if (!dragged || target.dataset.uDropFolder === dragged.id) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            target.classList.add('is-drop-target');
        });
        target.addEventListener('dragleave', () => target.classList.remove('is-drop-target'));
        target.addEventListener('drop', (event) => {
            event.preventDefault();
            target.classList.remove('is-drop-target');
            if (!dragged || target.dataset.uDropFolder === dragged.id) {
                return;
            }
            form.elements.contentid.value = dragged.id;
            form.elements.targetparentid.value = target.dataset.uDropFolder;
            form.elements.expectedmodified.value = dragged.expected;
            submitMove();
        });
    });
};
