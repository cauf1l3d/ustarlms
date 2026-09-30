(function () {
    'use strict';
    function init() {
        var root = document.querySelector('[data-notebook]');
        if (!root) { return; }
        var canvas = root.querySelector('.un-canvas'), sizer = root.querySelector('.un-sizer');
        if (!canvas) { return; }
        var revision = Number(root.dataset.revision), scale = 1, queue = Promise.resolve(), failed = false;
        var status = root.querySelector('[data-save-status]');
        var cards = Array.from(canvas.querySelectorAll('.un-card'));
        root.classList.add('is-spatial');
        root.querySelectorAll('[data-board-tools]').forEach(function (el) { el.hidden = false; });
        function size() {
            var width = 1040, height = 600;
            cards.forEach(function (card) {
                width = Math.max(width, Number(card.dataset.x) + 350);
                height = Math.max(height, Number(card.dataset.y) + card.offsetHeight + 40);
            });
            canvas.style.width = width + 'px'; canvas.style.height = height + 'px';
            canvas.style.transform = 'scale(' + scale + ')';
            sizer.style.width = width * scale + 'px'; sizer.style.height = height * scale + 'px';
        }
        function position(card, x, y) {
            card.dataset.x = String(Math.max(0, Math.min(5000, Math.round(x))));
            card.dataset.y = String(Math.max(0, Math.min(5000, Math.round(y))));
            card.style.left = card.dataset.x + 'px'; card.style.top = card.dataset.y + 'px';
            size();
        }
        function save(card) {
            var snapshot = {id: card.dataset.id, x: card.dataset.x, y: card.dataset.y, color: card.dataset.color};
            status.textContent = 'Сохраняем положение…';
            queue = queue.then(async function () {
                if (failed) { return; }
                var body = new URLSearchParams(Object.assign(snapshot, {action: 'move', revision: String(revision), sesskey: root.dataset.sesskey}));
                try {
                    var response = await fetch(root.dataset.url, {method: 'POST', credentials: 'same-origin', body: body, headers: {'Accept': 'application/json'}});
                    var result = await response.json();
                    if (!response.ok || !result.ok || !Number.isInteger(result.revision)) { throw new Error(result.error || 'Не удалось сохранить доску.'); }
                    revision = result.revision;
                    status.textContent = 'Положение сохранено';
                } catch (error) {
                    failed = true;
                    status.textContent = error.message + ' Обновите страницу перед следующим перемещением.';
                }
            });
        }
        cards.forEach(function (card) {
            position(card, Number(card.dataset.x), Number(card.dataset.y));
            var picker = card.querySelector('[data-color-picker]');
            picker.value = card.dataset.color;
            picker.addEventListener('change', function () { card.dataset.color = picker.value; save(card); });
            var handle = card.querySelector('[data-drag]'), drag = null;
            handle.addEventListener('pointerdown', function (e) {
                if (e.button !== 0 || failed) { return; }
                drag = {id: e.pointerId, px: e.clientX, py: e.clientY, x: Number(card.dataset.x), y: Number(card.dataset.y)};
                handle.setPointerCapture(e.pointerId); card.classList.add('is-dragging');
            });
            handle.addEventListener('pointermove', function (e) {
                if (!drag || e.pointerId !== drag.id) { return; }
                position(card, drag.x + (e.clientX - drag.px) / scale, drag.y + (e.clientY - drag.py) / scale);
            });
            function end(e) {
                if (!drag || e.pointerId !== drag.id) { return; }
                if (e.type === 'pointercancel') { position(card, drag.x, drag.y); }
                else if (Number(card.dataset.x) !== drag.x || Number(card.dataset.y) !== drag.y) { save(card); }
                drag = null; card.classList.remove('is-dragging');
            }
            handle.addEventListener('pointerup', end); handle.addEventListener('pointercancel', end);
            handle.addEventListener('keydown', function (e) {
                var delta = {ArrowLeft: [-20,0], ArrowRight: [20,0], ArrowUp: [0,-20], ArrowDown: [0,20]}[e.key];
                if (!delta || failed) { return; }
                e.preventDefault(); position(card, Number(card.dataset.x) + delta[0], Number(card.dataset.y) + delta[1]); save(card);
            });
        });
        root.querySelectorAll('[data-zoom]').forEach(function (button) {
            button.addEventListener('click', function () {
                scale = button.dataset.zoom === 'reset' ? 1 : Math.max(.5, Math.min(1.5, Math.round((scale + (button.dataset.zoom === 'in' ? .1 : -.1)) * 10) / 10));
                root.querySelector('[data-zoom-label]').textContent = Math.round(scale * 100) + '%'; size();
            });
        });
        if (window.ResizeObserver) { var observer = new ResizeObserver(size); cards.forEach(function (card) { observer.observe(card); }); }
        size();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
