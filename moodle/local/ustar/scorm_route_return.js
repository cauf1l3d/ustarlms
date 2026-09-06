(function () {
    'use strict';

    const body = document.body;

    if (!body) {
        return;
    }

    const cmid =
        Number(body.dataset.ustarScormCmid || 0);

    const statusUrl =
        body.dataset.ustarScormStatusUrl || '';

    const routeUrl =
        body.dataset.ustarRouteUrl || '';

    if (!cmid || !statusUrl) {
        return;
    }

    const bar = document.createElement('div');

    bar.className = 'u-scorm-route-return';

    bar.innerHTML =
        '<div class="u-scorm-route-return__text">'
        + '<strong>Товароведение</strong>'
        + '<span data-scorm-state>Пройдите материал до конца</span>'
        + '</div>'
        + '<div class="u-scorm-route-return__actions">'
        + '<a href="' + routeUrl + '">← К маршруту</a>'
        + '<button type="button" class="u-btn u-btn--primary" '
        + 'data-scorm-finish hidden>'
        + 'Завершить и перейти к аттестации →'
        + '</button>'
        + '</div>';

    document.body.appendChild(bar);

    const state =
        bar.querySelector('[data-scorm-state]');

    const button =
        bar.querySelector('[data-scorm-finish]');

    let busy = false;
    let ready = false;
    let sesskey = '';

    async function check() {
        if (
            busy
            || document.hidden
        ) {
            return;
        }

        busy = true;

        try {
            const response =
                await fetch(
                    statusUrl
                    + '?cmid='
                    + encodeURIComponent(cmid),
                    {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }
                );

            const data =
                await response.json();

            sesskey = data.sesskey || sesskey;

            if (
                data.active
                && data.ready
            ) {
                ready = true;

                bar.classList.add('is-complete');

                state.textContent =
                    'Материал пройден. Подтвердите завершение точки.';

                button.hidden = false;
            }

        } catch (e) {
            // Polling failure must never break the SCORM itself.
        } finally {
            busy = false;
        }
    }

    button.addEventListener('click', async function () {
        if (
            !ready
            || busy
        ) {
            return;
        }

        busy = true;
        button.disabled = true;
        state.textContent = 'Сохраняем завершение…';

        try {
            const payload =
                new URLSearchParams();

            payload.set('cmid', String(cmid));
            payload.set('confirm', '1');
            payload.set('sesskey', sesskey);

            const response =
                await fetch(
                    statusUrl,
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body: payload.toString()
                    }
                );

            const data =
                await response.json();

            if (
                data.confirmed
                && data.targeturl
            ) {
                state.textContent =
                    data.hasnext
                        ? 'Завершено. Открываем аттестацию…'
                        : 'Завершено. Возвращаемся в маршрут…';

                window.location.href =
                    data.targeturl;

                return;
            }

            throw new Error('confirmation failed');

        } catch (e) {
            state.textContent =
                'Не удалось сохранить. Нажмите ещё раз.';
            button.disabled = false;
        } finally {
            busy = false;
        }
    });

    window.setInterval(check, 1200);
    window.setTimeout(check, 700);
})();
