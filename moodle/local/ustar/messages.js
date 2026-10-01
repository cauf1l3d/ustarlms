(function () {
    'use strict';
    function init() {
        var root = document.querySelector('[data-chat-root]');
        if (!root) return;
        var thread = root.querySelector('[data-chat-messages]');
        var form = root.querySelector('[data-chat-compose]');
        var panel = root.querySelector('.u-messenger');
        if (!panel) return;
        var busy = false, polling = false, stopped = false, signature = '';
        var search = new URLSearchParams(location.search);
        if (search.has('q') || search.has('list')) panel.classList.add('is-list');
        root.querySelectorAll('[data-conversation-id]').forEach(function (link) {
            if (link.dataset.conversationId === root.dataset.conversation) link.setAttribute('aria-current', 'page');
        });
        function sizePanel() {
            var viewport = window.visualViewport;
            var height = viewport ? viewport.height : window.innerHeight;
            var bottom = document.querySelector('.u-bottomnav');
            var keyboard = viewport && window.innerHeight - viewport.height > 140;
            document.body.classList.toggle('u-chat-keyboard', !!keyboard);
            var reserve = bottom && getComputedStyle(bottom).display !== 'none' ? bottom.getBoundingClientRect().height : 0;
            panel.style.setProperty('--chat-height', Math.max(220, height - panel.getBoundingClientRect().top - reserve - 16) + 'px');
        }
        sizePanel();
        window.addEventListener('resize', sizePanel);
        window.addEventListener('scroll', sizePanel, {passive:true});
        if (window.visualViewport) window.visualViewport.addEventListener('resize', sizePanel);
        if (window.ResizeObserver) new ResizeObserver(sizePanel).observe(root.querySelector('header'));
        function newest() { if (thread) thread.scrollTop = thread.scrollHeight; }
        if (thread && thread.dataset.older !== 'true' && thread.dataset.older !== '1') newest();
        if (thread) thread.querySelectorAll('img').forEach(function (img) { img.addEventListener('load', newest, {once: true}); });
        function apply(data, sent) {
            if (!thread) return;
            var nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 100;
            var oldTop = thread.scrollTop;
            if (signature !== data.signature || sent) {
                signature = data.signature;
                thread.innerHTML = data.html;
                thread.dataset.older = 'false';
                if (nearBottom || sent) newest(); else thread.scrollTop = oldTop;
                thread.querySelectorAll('img').forEach(function (img) {
                    img.addEventListener('load', function () { if (nearBottom || sent) newest(); }, {once: true});
                });
            }
            if (form && !data.cansend) {
                stopped = true;
                form.querySelectorAll('input,textarea,button').forEach(function (input) { input.disabled = true; });
                form.querySelector('[data-chat-status]').textContent = 'Отправка в этот чат больше недоступна.';
            }
        }
        async function poll() {
            if (!thread || !root.dataset.conversation || root.dataset.conversation === '0' || busy || polling || stopped
                    || document.hidden || thread.dataset.older === 'true' || thread.dataset.older === '1'
                    || Array.from(root.querySelectorAll('audio,video')).some(function(m){return !m.paused;})) return;
            polling = true;
            var controller = new AbortController();
            var timer = setTimeout(function () { controller.abort(); }, 10000);
            try {
                var response = await fetch(root.dataset.api, {method: 'POST', credentials: 'same-origin',
                    signal: controller.signal, headers: {Accept: 'application/json'},
                    body: new URLSearchParams({action: 'poll', conversationid: root.dataset.conversation, sesskey: root.dataset.sesskey})});
                var data = await response.json();
                if (response.ok && data.ok) apply(data, false);
                else if (response.status === 400 || response.status === 403) {
                    stopped = true;
                    if (form) {
                        form.querySelectorAll('input,textarea,button').forEach(function (input) { input.disabled = true; });
                        form.querySelector('[data-chat-status]').textContent = 'Обновите страницу и проверьте доступ к чату.';
                    }
                }
            } catch (e) { /* Keep the draft and history during a transient disconnection. */ }
            finally { clearTimeout(timer); polling = false; }
        }
        setInterval(poll, 20000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
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
                event.preventDefault(); if (busy) return;
                var text = form.querySelector('textarea');
                var problem = error();
                if (!text.value.trim() && !files.files.length) problem = 'Напишите сообщение или приложите файл.';
                if (problem) { status.textContent = problem; return; }
                busy = true; status.textContent = 'Отправляем…'; progress.hidden = !files.files.length; progress.value = 0;
                var body = new FormData(form), xhr = new XMLHttpRequest();
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
                xhr.onerror = xhr.ontimeout = function () { status.textContent = 'Ответ сервера не получен. Черновик сохранён. Обновите чат перед повторной отправкой.'; };
                xhr.onloadend = function () { busy = false; if (!stopped) { send.disabled = false; files.disabled = false; text.readOnly = false; } progress.hidden = true; sizePanel(); };
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
                    controller = new AbortController(); results.textContent = 'Ищем…';
                    try {
                        var response = await fetch(root.dataset.api, {method:'POST', credentials:'same-origin', signal:controller.signal,
                            body:new URLSearchParams({action:'search', q:query, sesskey:root.dataset.sesskey})});
                        var data = await response.json(); if (!response.ok || !data.ok) throw new Error();
                        results.replaceChildren();
                        data.users.forEach(function (person) {
                            var button = document.createElement('button'); button.type = 'button'; button.className = 'u-search-person'; button.textContent = person.fullname;
                            button.addEventListener('click', function () { people.set(person.id, person); renderSelected(); input.value = ''; results.replaceChildren(); input.focus(); }); results.append(button);
                        }); if (!data.users.length) results.textContent = 'Доступные коллеги не найдены.';
                    } catch (e) { if (e.name !== 'AbortError') results.textContent = 'Поиск недоступен. Попробуйте ещё раз.'; }
                }, 250);
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
