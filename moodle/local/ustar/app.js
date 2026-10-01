(function () {
    'use strict';
    function init() {
        var viewport = document.querySelector('meta[name=viewport]');
        if (viewport && !viewport.content.includes('viewport-fit')) viewport.content += ',viewport-fit=cover';
        var dialog = document.getElementById('u-mobile-menu'), trigger = document.querySelector('[data-mobile-menu-open]');
        if (dialog && trigger) {
            function close() {
                if (typeof dialog.close === 'function' && dialog.open) dialog.close();
                dialog.classList.remove('is-fallback'); dialog.removeAttribute('open'); trigger.focus();
            }
            trigger.addEventListener('click', function () {
                if (typeof dialog.showModal === 'function') dialog.showModal();
                else { dialog.setAttribute('open', ''); dialog.classList.add('is-fallback'); dialog.querySelector('button').focus(); }
            });
            dialog.querySelector('[data-mobile-menu-close]').addEventListener('click', close);
            dialog.addEventListener('click', function (event) { if (event.target === dialog) close(); });
            dialog.addEventListener('close', function () { trigger.focus(); });
            document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && dialog.classList.contains('is-fallback')) close(); });
        }
        document.querySelectorAll('.u-main table').forEach(function (table) {
            if (table.closest('.u-table-scroll,.generaltable-wrapper,.uw-table-wrap') || table.closest('.u-notebook-canvas')) return;
            var scroll = document.createElement('div'); scroll.className = 'u-table-scroll'; scroll.tabIndex = 0;
            scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', 'Таблица — прокрутка по горизонтали');
            table.parentNode.insertBefore(scroll, table); scroll.append(table);
        });
        var install = document.querySelector('[data-app-install]'), help = document.querySelector('[data-app-install-help]'), prompt = null;
        var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
        var script = Array.from(document.scripts).find(function (item) { return /\/local\/ustar\/app\.js(?:\?|$)/.test(item.src); });
        if (!script) return;
        var base = script.src.split('/local/ustar/app.js')[0];
        if (!window.isSecureContext) {
            if (help) help.textContent = 'Для установки приложения откройте Академию по защищённому адресу HTTPS.';
            return;
        }
        if ('serviceWorker' in navigator) {
            var register = function () {
                navigator.serviceWorker.register(base + '/local/ustar/app_worker.php', {scope: new URL(base + '/').pathname})
                    .catch(function () { if (help) help.textContent = 'Установка сейчас недоступна. Академия продолжает работать в браузере.'; });
            };
            if (document.readyState === 'complete') register(); else window.addEventListener('load', register, {once:true});
        }
        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault(); prompt = event;
            if (install && !standalone) install.hidden = false;
            if (help) help.textContent = '';
        });
        if (install) install.addEventListener('click', async function () {
            if (!prompt) return;
            await prompt.prompt(); await prompt.userChoice; prompt = null; install.hidden = true;
        });
        window.addEventListener('appinstalled', function () { standalone = true; if (install) install.hidden = true; if (help) help.textContent = 'Академия добавлена на домашний экран.'; });
        if (help && !standalone) {
            help.textContent = /iPhone|iPad|iPod/.test(navigator.userAgent)
                ? 'В Safari откройте «Поделиться» → «На экран Домой».'
                : 'Добавьте Академию на домашний экран через меню браузера.';
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
