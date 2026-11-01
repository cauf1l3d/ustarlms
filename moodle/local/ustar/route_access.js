(function() {
    'use strict';
    document.querySelectorAll('[data-route-advance]').forEach(form => {
        form.addEventListener('submit', event => {
            const text = form.dataset.closesAccess === '1'
                ? 'После перехода материал будет недоступен для повторного изучения из этой точки. Результат сохранится. Перейти дальше?'
                : 'Перейти к следующему шагу? Материал останется доступным для повторения.';
            if (!window.confirm(text)) { event.preventDefault(); return; }
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'confirmed'; input.value = '1'; form.appendChild(input);
        });
    });
    const clocks = [...document.querySelectorAll('[data-route-deadline]')];
    const loaded = performance.now();
    let submitted = false;
    function update() {
        let expired = false;
        clocks.forEach(clock => {
            const seconds = Math.max(0, Math.ceil(Number(clock.dataset.routeDeadline) - Number(clock.dataset.routeNow)
                - (performance.now() - loaded) / 1000));
            clock.textContent = seconds ? 'Для повторения осталось: ' + Math.floor(seconds / 3600) + ' ч '
                + Math.floor(seconds % 3600 / 60) + ' мин' : 'Срок повторения завершён';
            expired = expired || seconds === 0;
        });
        const sync = document.querySelector('[data-route-deadline-sync]');
        if (expired && !submitted && sync) { submitted = true; sync.submit(); }
    }
    if (clocks.length) { update(); setInterval(update, 1000); }
})();
