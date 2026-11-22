(function () {
    'use strict';
    function init(root) {
        const thread = root.querySelector('[data-chat-messages]');
        const items = thread && thread.querySelector('[data-chat-items]');
        const older = thread && thread.querySelector('[data-chat-older]');
        const empty = thread && thread.querySelector('[data-chat-empty]');
        const form = root.querySelector('[data-chat-compose]');
        const panel = root.querySelector('.u-messenger');
        const params = root.dataset.taskid && root.dataset.taskid !== '0'
            ? {taskid: root.dataset.taskid} : {conversationid: root.dataset.conversation};
        const canpoll = !!items && !thread.dataset.older && !thread.dataset.search;
        let refresh = null, tick = 0, delay = 5000, onscreen = !!panel, busy = false, stopped = false, frame = 0;
        let maySend = !!form;
        root.querySelectorAll('[data-conversation-id], [data-chat-task-id]').forEach(link => {
            if (link.dataset.conversationId === params.conversationid && params.conversationid ||
                    link.dataset.chatTaskId === params.taskid && params.taskid) {
                link.setAttribute('aria-current', 'page');
            }
        });
        function sizePanel() {
            if (!panel || frame) return;
            frame = requestAnimationFrame(() => {
                const viewport = window.visualViewport;
                const height = viewport ? viewport.height : window.innerHeight;
                const bottom = document.querySelector('.u-bottomnav');
                const keyboard = viewport && window.innerHeight - viewport.height > 140;
                document.body.classList.toggle('u-chat-keyboard', !!keyboard);
                const reserve = bottom && getComputedStyle(bottom).display !== 'none' ? bottom.getBoundingClientRect().height : 0;
                panel.style.setProperty('--chat-height', Math.max(220, height - panel.getBoundingClientRect().top - reserve - 16) + 'px');
                frame = 0;
            });
        }
        if (panel) {
            sizePanel();
            window.addEventListener('resize', sizePanel);
            window.addEventListener('scroll', sizePanel, {passive: true});
            if (window.visualViewport) window.visualViewport.addEventListener('resize', sizePanel);
            const header = root.querySelector('header');
            if (header && window.ResizeObserver) new ResizeObserver(sizePanel).observe(header);
        }
        const atEnd = () => thread.scrollHeight - thread.scrollTop - thread.clientHeight < 85;
        const latest = () => { thread.scrollTop = thread.scrollHeight; };
        const ids = () => Array.from(items.children, item => item.dataset.messageId);
        if (canpoll) {
            latest();
            items.addEventListener('load', event => {
                if (event.target.tagName === 'IMG' && atEnd()) latest();
            }, true);
        }
        function addWindow(body) {
            body.append('delta', '1');
            ids().forEach(id => body.append('known[]', id));
        }
        function updateSendPermission(cansend) {
            if (!form || cansend === undefined) return;
            const send = form.querySelector('button[type=submit]');
            maySend = cansend;
            send.disabled = busy || !maySend;
            if (!cansend) form.querySelector('[data-chat-status]').textContent = 'Отправка в этот чат недоступна.';
        }
        function apply(data, sent) {
            if (!items || !data.delta) return false;
            const wanted = data.ids.map(String), existing = ids();
            const changed = wanted.join(',') !== existing.join(',') || !!data.html;
            updateSendPermission(data.cansend);
            older.hidden = !data.olderurl;
            if (data.olderurl) older.href = data.olderurl;
            if (!changed) return false;
            const follow = sent || atEnd();
            const top = thread.getBoundingClientRect().top;
            const anchor = follow ? null : Array.from(items.children).find(node =>
                wanted.includes(node.dataset.messageId) && node.getBoundingClientRect().bottom > top);
            const anchorTop = anchor && anchor.getBoundingClientRect().top;
            const nodes = new Map(Array.from(items.children, node => [node.dataset.messageId, node]));
            const added = document.createElement('template');
            added.innerHTML = data.html;
            if (follow) {
                added.content.querySelectorAll('img').forEach(img => img.addEventListener('load', latest, {once: true}));
            }
            added.content.querySelectorAll('[data-message-id]').forEach(node => nodes.set(node.dataset.messageId, node));
            const kept = new Set(wanted);
            Array.from(items.children).forEach(node => { if (!kept.has(node.dataset.messageId)) node.remove(); });
            let next = items.firstElementChild;
            wanted.forEach(id => {
                const node = nodes.get(id);
                if (!node) return;
                if (node !== next) items.insertBefore(node, next);
                next = node.nextElementSibling;
            });
            empty.hidden = !!wanted.length;
            if (follow) {
                latest();
            } else if (anchor && anchor.isConnected) {
                thread.scrollTop += anchor.getBoundingClientRect().top - anchorTop;
            }
            return true;
        }
        function schedule(ms) {
            clearTimeout(tick);
            if (canpoll && onscreen && !document.hidden && !stopped) tick = setTimeout(poll, ms);
        }
        function revoke() {
            stopped = true;
            // Remove the already displayed conversation when the server reports it is no longer available.
            items.replaceChildren();
            older.hidden = true;
            empty.hidden = false;
            empty.textContent = 'Чат недоступен. Откройте список диалогов, чтобы обновить доступ.';
            if (form) form.hidden = true;
        }
        async function poll() {
            if (busy || refresh || Array.from(thread.querySelectorAll('audio,video')).some(media => !media.paused)) {
                schedule(delay);
                return;
            }
            const controller = new AbortController();
            refresh = controller;
            const timer = setTimeout(() => controller.abort(), 10000);
            const body = new URLSearchParams({...params, action: 'poll', sesskey: root.dataset.sesskey, read: Number(atEnd())});
            addWindow(body);
            try {
                const response = await fetch(root.dataset.api, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
                    headers: {Accept: 'application/json'}, body});
                if (busy || controller.signal.aborted) return;
                if (response.status === 400 || response.status === 403) {
                    revoke();
                    return;
                }
                const data = await response.json();
                if (response.ok && data.ok && !busy && !controller.signal.aborted) {
                    delay = apply(data, false) ? 5000 : Math.min(20000, delay + 5000);
                } else {
                    delay = 20000;
                }
            } catch (e) {
                delay = 20000;
            } finally {
                clearTimeout(timer);
                refresh = null;
                schedule(delay);
            }
        }
        if (canpoll) {
            if (!panel && window.IntersectionObserver) {
                new IntersectionObserver(entries => {
                    onscreen = entries.some(entry => entry.isIntersecting);
                    if (!onscreen && refresh) refresh.abort();
                    schedule(onscreen ? 0 : delay);
                }).observe(root);
            } else {
                onscreen = true;
                schedule(delay);
            }
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && refresh) refresh.abort();
                schedule(document.hidden ? delay : 0);
            });
        }
        if (form) {
            function requestId() { return Array.from(crypto.getRandomValues(new Uint8Array(16)),function(n){return n.toString(16).padStart(2,'0');}).join(''); }
            var files = form.querySelector('input[type=file]'), status = form.querySelector('[data-chat-status]');
            var previews = form.querySelector('[data-chat-uploads]'), progress = form.querySelector('progress');
            var objects = [];
            function error() {
                var list = Array.from(files.files), total = list.reduce(function (sum, file) { return sum + file.size; }, 0);
                if (list.length > 5) return 'Выберите не более 5 файлов.';
                if (list.some(function (file) { return file.size > Number(form.dataset.maxbytes) || file.size === 0; })) return 'Файл пустой или превышает допустимый размер.';
                if (total > 50 * 1024 * 1024 || Number(form.dataset.maxpostbytes) > 0 && total + 8192 > Number(form.dataset.maxpostbytes)) return 'Вложения слишком велики для одной отправки. Отправьте их отдельными сообщениями.';
                return '';
            }
            function showFiles() {
                objects.forEach(URL.revokeObjectURL); objects = []; previews.replaceChildren();
                Array.from(files.files).forEach(function (file, index) {
                    var chip = document.createElement('div'); chip.className = 'u-chat-upload';
                    if (['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
                        var img = document.createElement('img'); img.alt = ''; img.src = URL.createObjectURL(file); objects.push(img.src); chip.append(img);
                    }
                    var label = document.createElement('span'); label.textContent = file.name; chip.append(label);
                    var remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', 'Убрать ' + file.name);
                    remove.addEventListener('click', function () {
                        if (busy) return;
                        if (typeof DataTransfer === 'function') {
                            var transfer = new DataTransfer(); Array.from(files.files).forEach(function (item, i) { if (i !== index) transfer.items.add(item); }); files.files = transfer.files;
                        } else files.value = '';
                        form.querySelector('[name=requestid]').value = requestId(); showFiles();
                    }); chip.append(remove); previews.append(chip);
                });
                status.textContent = error(); sizePanel();
            }
            files.addEventListener('change', function () { form.querySelector('[name=requestid]').value = requestId(); showFiles(); });
            form.querySelector('textarea').addEventListener('input', function () { if (!busy) form.querySelector('[name=requestid]').value = requestId(); });
            form.querySelector('textarea').addEventListener('keydown', function (event) {
                if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); if (!busy) form.requestSubmit(); }
            });
            form.addEventListener('submit', function (event) {
                event.preventDefault(); if (busy || !maySend) return;
                var text = form.querySelector('textarea');
                var problem = error();
                if (!text.value.trim() && !files.files.length) problem = 'Напишите сообщение или приложите файл.';
                if (problem) { status.textContent = problem; return; }
                busy = true; status.textContent = 'Отправляем…'; progress.hidden = !files.files.length; progress.value = 0;
                if (refresh) refresh.abort();
                var body = new FormData(form), xhr = new XMLHttpRequest();
                addWindow(body);
                var send = form.querySelector('button[type=submit]'); send.disabled = true; files.disabled = true; text.readOnly = true;
                xhr.open('POST', root.dataset.api); xhr.timeout = 120000; xhr.setRequestHeader('Accept', 'application/json');
                xhr.upload.addEventListener('progress', function (e) { if (e.lengthComputable) progress.value = Math.round(100 * e.loaded / e.total); });
                xhr.onload = function () {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (xhr.status < 200 || xhr.status >= 300 || !data.ok) throw new Error(data.error || 'Не удалось отправить сообщение.');
                        text.value = ''; files.value = '';
                        form.querySelector('[name=requestid]').value = requestId();
                        showFiles(); apply(data, true); status.textContent = 'Сообщение отправлено.';
                    } catch (e) { status.textContent = e.message; }
                };
                xhr.onerror = xhr.ontimeout = function () { status.textContent = 'Ответ сервера не получен. Черновик сохранён. Можно повторить отправку.'; };
                xhr.onloadend = function () {
                    busy = false;
                    if (!stopped) { send.disabled = !maySend; files.disabled = false; text.readOnly = false; }
                    progress.hidden = true; sizePanel(); schedule(delay);
                };
                xhr.send(body);
            });
        }
        root.querySelectorAll('[data-chat-picker]').forEach(function (picker) {
            var input = picker.querySelector('[data-chat-person-search]'), results = picker.querySelector('[data-chat-person-results]');
            var selected = picker.querySelector('[data-chat-selected]'), people = new Map(), timer, controller;
            function renderSelected() {
                selected.replaceChildren(); people.forEach(function (person) {
                    var chip = document.createElement('label'); chip.className = 'u-chat-person';
                    var hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'members[]'; hidden.value = person.id;
                    var name = document.createElement('span'); name.textContent = person.fullname;
                    var remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', 'Убрать ' + person.fullname);
                    remove.addEventListener('click', function () { people.delete(person.id); renderSelected(); }); chip.append(hidden, name, remove); selected.append(chip);
                }); sizePanel();
            }
            input.addEventListener('input', function () {
                clearTimeout(timer); if (controller) controller.abort();
                var query = input.value.trim(); results.replaceChildren(); if (query.length < 2) return;
                timer = setTimeout(async function () {
                    const request = new AbortController();
                    controller = request; results.textContent = 'Ищем…';
                    try {
                        var response = await fetch(root.dataset.api, {method:'POST', credentials:'same-origin', signal:request.signal,
                            body:new URLSearchParams({action:'search', q:query, sesskey:root.dataset.sesskey})});
                        var data = await response.json(); if (!response.ok || !data.ok) throw new Error();
                        if (controller !== request || input.value.trim() !== query) return;
                        results.replaceChildren();
                        data.users.forEach(function (person) {
                            var button = document.createElement('button'); button.type = 'button'; button.className = 'u-search-person'; button.textContent = person.fullname;
                            button.addEventListener('click', function () { people.set(person.id, person); renderSelected(); input.value = ''; results.replaceChildren(); input.focus(); }); results.append(button);
                        }); if (!data.users.length) results.textContent = 'Доступные коллеги не найдены.';
                    } catch (e) { if (e.name !== 'AbortError' && controller === request) results.textContent = 'Поиск недоступен. Попробуйте ещё раз.'; }
                }, 250);
            });
        });
    }
    function boot() { document.querySelectorAll('[data-chat-root]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
