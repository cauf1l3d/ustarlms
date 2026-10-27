export const init = () => {
    const root = document.querySelector('[data-materials-view]');
    if (root && !root.dataset.materialsInitialized) {
        root.dataset.materialsInitialized = 'true';
        const key = root.dataset.uViewKey;
        const buttons = [...root.querySelectorAll('[data-u-material-view]')];
        const apply = (view) => {
            if (!['list', 'blocks', 'cards'].includes(view)) {
                view = 'cards';
            }
            root.dataset.materialsView = view;
            buttons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.uMaterialView === view)));
        };
        let stored = 'cards';
        try { stored = localStorage.getItem(key) || 'cards'; } catch (error) { /* Browser storage is optional. */ }
        apply(stored);
        buttons.forEach((button) => button.addEventListener('click', () => {
            const view = button.dataset.uMaterialView;
            apply(view);
            try { localStorage.setItem(key, view); } catch (error) { /* The current view remains usable. */ }
        }));
        const menus = [...root.querySelectorAll('details')];
        const place = (menu) => {
            if (!menu.classList.contains('u-material-row-menu') || !menu.open) { return; }
            const box = menu.querySelector('summary').getBoundingClientRect();
            const below = innerHeight - box.bottom - 16;
            const above = box.top - 16;
            const upwards = below < 280 && above > below;
            menu.dataset.menuAbove = String(upwards);
            menu.style.setProperty('--material-menu-height', Math.max(100, Math.min(360, upwards ? above : below)) + 'px');
        };
        menus.forEach((menu) => menu.addEventListener('toggle', () => {
            if (menu.open) {
                menus.filter((other) => other !== menu).forEach((other) => other.open = false);
                place(menu);
            }
        }));
        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                const open = menus.find((menu) => menu.open);
                if (open) { open.open = false; open.querySelector('summary').focus(); event.preventDefault(); }
            }
        });
        document.addEventListener('click', (event) => menus.forEach((menu) => {
            if (!menu.contains(event.target)) { menu.open = false; }
        }));
        window.addEventListener('resize', () => menus.forEach(place));
    }
    const form = document.querySelector('[data-u-move-form]');
    if (!form) {
        return;
    }

    const status = document.querySelector('[data-u-move-status]');
    const workspace = document.querySelector('.u-materials-workspace');
    const submitMove = () => {
        if (form.getAttribute('aria-busy') === 'true') { return; }
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
            if (event.target.closest('details,input,select,button') || row.getAttribute('draggable') !== 'true') {
                event.preventDefault();
                return;
            }
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
