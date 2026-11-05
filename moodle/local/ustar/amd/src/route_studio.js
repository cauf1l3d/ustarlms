export const init = () => {
    document.querySelector('[data-u-form-error]')?.focus();
    const list = document.getElementById('u-route-list');
    if (!list) {
        return;
    }

    let dragged = null;

    const visiblePoints = () => [...list.querySelectorAll('.u-route-editor__point')].filter(item => item.dataset.uPointActive !== '0');
    const renumber = () => {
        const points = visiblePoints();
        points.forEach((item, index) => {
            const number = item.querySelector('.u-route-item__order');
            if (number) {
                number.textContent = String(index + 1);
            }
            const up = item.querySelector('[data-u-move="up"]');
            const down = item.querySelector('[data-u-move="down"]');
            if (up) {
                up.disabled = index === 0;
            }
            if (down) {
                down.disabled = index === points.length - 1;
            }
        });
    };

    list.querySelectorAll('.u-route-editor__point').forEach((item) => {
        item.addEventListener('dragstart', (event) => {
            if (item.dataset.uDraggable !== '1') {
                event.preventDefault();
                return;
            }

            dragged = item;
            item.classList.add('is-dragging');
        });

        item.addEventListener('dragend', () => {
            item.classList.remove('is-dragging');
            dragged = null;
            renumber();
        });

        item.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (
                !dragged
                || dragged === item
                || item.dataset.uDraggable !== '1'
            ) {
                return;
            }
            const rect = item.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            if (after) {
                item.after(dragged);
            } else {
                item.before(dragged);
            }
        });

        item.querySelectorAll('[data-u-move]').forEach((button) => {
            button.addEventListener('click', () => {
                const direction = button.dataset.uMove;
                const points = visiblePoints();
                const index = points.indexOf(item);
                if (direction === 'up' && index > 0) { points[index - 1].before(item); }
                if (direction === 'down' && index >= 0 && index < points.length - 1) { points[index + 1].after(item); }
                renumber();
                item.focus?.();
            });
        });
    });

    renumber();
};
