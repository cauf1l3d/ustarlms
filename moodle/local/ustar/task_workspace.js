/* Progressive enhancement only: permissions, versions and persistence stay server-side. */
(function () {
    'use strict';
    function enableBoard(board) {
        let card = null;
        const active = () => board.querySelector('.is-drop-target');
        const clear = () => {
            active()?.classList.remove('is-drop-target');
            card?.classList.remove('is-dragging');
            card = null;
        };
        const moveAction = column => {
            if (!column || !card) { return null; }
            const target = column.dataset.boardColumn;
            const select = card.querySelector('select[name="column"]');
            if (select && target !== card.closest('[data-board-column]').dataset.boardColumn) {
                return {button: select.form.querySelector('button[type="submit"]'), select, target};
            }
            const button = Array.from(card.querySelectorAll('button[name="target"]')).find(item => item.value === target);
            return button ? {button} : null;
        };
        board.addEventListener('dragstart', event => {
            const target = event.target.closest('.uw-board-card[draggable="true"]');
            if (!target) { return; }
            card = target;
            card.classList.add('is-dragging');
            event.dataTransfer.setData('text/plain', 'ustar-task');
            event.dataTransfer.effectAllowed = 'move';
        });
        board.addEventListener('dragover', event => {
            const column = event.target.closest('[data-board-column]');
            active()?.classList.remove('is-drop-target');
            if (moveAction(column)) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                column.classList.add('is-drop-target');
            }
        });
        board.addEventListener('drop', event => {
            const move = moveAction(event.target.closest('[data-board-column]'));
            if (move) {
                event.preventDefault();
                clear();
                if (move.select) { move.select.value = move.target; }
                move.button.form.requestSubmit(move.button);
            }
        });
        board.addEventListener('dragend', clear);
    }
    function enableAvailability(panel) {
        const form = panel.closest('form');
        const button = panel.querySelector('button');
        const status = panel.querySelector('[role="status"]');
        let pending = null;
        button.hidden = false;
        form.addEventListener('input', event => {
            if (!['starttime', 'endtime', 'roomid', 'participants[]'].includes(event.target.name)) return;
            if (pending) pending.abort();
            status.textContent = '';
        });
        button.addEventListener('click', async () => {
            for (const name of ['starttime', 'endtime']) {
                if (!form.elements[name].reportValidity()) return;
            }
            const body = new URLSearchParams({
                sesskey: form.elements.sesskey.value, meetingid: panel.dataset.meetingId,
                starttime: form.elements.starttime.value, endtime: form.elements.endtime.value, roomid: form.elements.roomid.value,
            });
            form.querySelectorAll('[name="participants[]"]:checked').forEach(input => body.append('participants[]', input.value));
            const controller = new AbortController();
            pending = controller;
            button.disabled = true;
            status.textContent = 'Проверяем расписание…';
            const timeout = setTimeout(() => controller.abort('timeout'), 10000);
            try {
                const response = await fetch(panel.dataset.meetingCheck, {method: 'POST', credentials: 'same-origin',
                    signal: controller.signal, headers: {Accept: 'application/json'}, body});
                const value = await response.json();
                if (controller.signal.aborted) return;
                if (!response.ok || !value.ok) {
                    status.textContent = value.error || 'Не удалось проверить расписание.';
                    return;
                }
                const conflicts = [];
                if (!value.organizerfree) conflicts.push('У вас уже есть встреча в это время.');
                if (!value.roomfree) conflicts.push('Переговорная занята.');
                if (!value.capacityok) conflicts.push('В переговорной не хватит мест.');
                if (value.busy.length) conflicts.push('Заняты ' + value.busy.join(', ') + '.');
                status.textContent = conflicts.length ? conflicts.join(' ') : 'Пересечений по выбранному времени нет.';
            } catch (error) {
                if (!controller.signal.aborted || controller.signal.reason === 'timeout') {
                    status.textContent = 'Не удалось проверить расписание. Повторите попытку.';
                }
            } finally {
                clearTimeout(timeout);
                pending = null;
                button.disabled = false;
            }
        });
    }
    function enableAssignment(editor) {
        const catalog = JSON.parse(editor.dataset.catalog);
        const limit = Number(editor.dataset.limit);
        const picker = editor.querySelector('[data-assignment-picker]');
        const body = editor.querySelector('[data-assignment-body]');
        const form = body.querySelector('form');
        const {targetmode, departmentid, positionid, assigneeid} = picker.elements;
        const summary = editor.querySelector('[data-assignment-summary]');
        const list = editor.querySelector('[data-assignment-list]');
        const personName = editor.querySelector('[data-assignment-person-name]');
        const search = editor.querySelector('[data-assignment-search]');
        const send = form.querySelector('[data-assignment-send]');
        const topics = form.elements.policyid;
        let currentTopicUser = topics?.options.length > 1 ? form.elements.assigneeid.value : '';
        let pending = null;

        function options(select, entries, placeholder) {
            const selected = select.value;
            select.replaceChildren(new Option(placeholder, ''), ...entries.map(([id, label]) => new Option(label, id)));
            if (entries.some(([id]) => id === selected)) {
                select.value = selected;
            }
        }

        function updateTopics() {
            if (!topics || form.elements.kind.value !== 'retraining' || !form.elements.assignmenttoken.value) {
                pending?.abort();
                pending = null;
                return;
            }
            const user = assigneeid.value;
            if (currentTopicUser === user || pending?.user === user) {
                send.disabled = pending?.user === user || !topics.value || topics.value === '0';
                return;
            }
            pending?.abort();
            const controller = new AbortController();
            const request = {user, abort: () => controller.abort()};
            pending = request;
            currentTopicUser = '';
            options(topics, [], 'Загружаем материалы…');
            const status = editor.querySelector('[data-assignment-topics-status]');
            status.textContent = 'Проверяем, какие материалы уже пройдены.';
            send.disabled = true;
            const params = new URLSearchParams({
                action: 'assignment_topics', sesskey: form.elements.sesskey.value, assigneeid: user,
            });
            fetch(form.action, {method: 'POST', body: params, signal: controller.signal, credentials: 'same-origin'})
                .then(response => {
                    if (!response.ok) { throw new Error('Topics request failed'); }
                    return response.json();
                }).then(data => {
                    if (pending !== request) { return; }
                    const entries = Object.entries(data.topics);
                    options(topics, entries, 'Выберите тему');
                    status.textContent = entries.length ? 'Выберите подтверждённое прохождение.'
                        : 'Нет доступных ранее пройденных материалов.';
                    currentTopicUser = user;
                }).catch(() => {
                    if (pending !== request) { return; }
                    status.textContent = 'Материалы не загрузились. Выберите «Поручение» и вернитесь к повторному обучению.';
                    options(topics, [], 'Материалы недоступны');
                }).finally(() => {
                    if (pending === request) { pending = null; }
                });
        }

        function update() {
            const mode = targetmode.value;
            const dept = departmentid.value;
            const matchDept = row => !dept || row.department === dept;
            options(positionid, Object.entries(catalog.positions).filter(([, row]) => matchDept(row))
                .map(([id, row]) => [id, row.name]), mode === 'position' ? 'Выберите должность' : 'Все доступные должности');
            const position = positionid.value;
            const query = search.querySelector('input').value.toLocaleLowerCase('ru').trim();
            options(assigneeid, Object.entries(catalog.people).filter(([id, row]) =>
                matchDept(row) && (!position || row.position === position)
                && (id === assigneeid.value || row.name.toLocaleLowerCase('ru').includes(query)))
                .map(([id, row]) => [id, row.name]), 'Выберите сотрудника');
            editor.querySelector('[data-assignment-position]').hidden = mode === 'department';
            editor.querySelector('[data-assignment-person]').hidden = mode !== 'person';
            const selected = mode === 'department' ? catalog.departments[dept]
                : mode === 'position' ? catalog.positions[position] : catalog.people[assigneeid.value];
            const ids = mode === 'position' ? (selected?.members ?? [])
                : selected?.token ? [mode === 'department' ? selected.head : assigneeid.value] : [];
            const count = ids.length;
            const valid = count > 0 && count <= limit;
            list.hidden = !valid || count < 2;
            list.querySelector('ul').replaceChildren(...(valid && count > 1 ? ids.map(id => {
                const item = document.createElement('li');
                item.textContent = catalog.people[id].name;
                return item;
            }) : []));
            summary.textContent = count > limit ? 'За один раз можно назначить задачу не более чем ' + limit + ' сотрудникам.'
                : mode === 'department' ? (valid ? 'Получатель — руководитель отдела'
                    : dept ? 'У отдела нет однозначного руководителя в ваших полномочиях.' : 'Выберите отдел.')
                : mode === 'position' ? (valid ? 'Получателей: ' + count + '. Каждый получит отдельную задачу.'
                    : 'Выберите должность.')
                : valid ? 'Получатель' : 'Выберите сотрудника. Отдел и должность можно использовать как фильтры.';
            personName.textContent = valid && count === 1 ? catalog.people[ids[0]].name : '';
            personName.hidden = !personName.textContent;
            body.hidden = !valid;
            form.elements.targetmode.value = mode;
            form.elements.departmentid.value = dept;
            form.elements.positionid.value = mode === 'department' ? '' : position;
            form.elements.assigneeid.value = mode === 'person' ? assigneeid.value : '0';
            form.elements.assignmenttoken.value = valid ? selected.token : '';
            send.textContent = mode === 'position' && valid ? 'Назначить сотрудникам (' + count + ')' : 'Назначить задачу';
            send.disabled = !valid;
            const option = form.querySelector('select[name="kind"] option[value="retraining"]');
            if (option) {
                option.disabled = mode !== 'person';
                if (option.disabled && option.selected) {
                    form.elements.kind.value = 'task';
                    form.elements.kind.dispatchEvent(new Event('change'));
                }
            }
            updateTopics();
        }

        picker.querySelector('[data-assignment-apply]').hidden = true;
        search.hidden = false;
        picker.addEventListener('submit', event => { event.preventDefault(); update(); });
        picker.addEventListener('change', update);
        search.addEventListener('input', update);
        form.elements.kind.addEventListener('change', update);
        topics?.addEventListener('change', updateTopics);
        let submitting = false;
        form.addEventListener('submit', event => {
            if (submitting || send.disabled || !form.elements.assignmenttoken.value) {
                event.preventDefault();
                return;
            }
            submitting = true;
            send.disabled = true;
        });
        window.addEventListener('pageshow', event => {
            if (event.persisted) { submitting = false; update(); }
        });
        update();
    }

    function init() {
        const root = document.querySelector('.u-workspace');
        if (!root) { return; }
        root.querySelectorAll('[data-task-board]').forEach(enableBoard);
        root.querySelectorAll('[data-meeting-check]').forEach(enableAvailability);
        const people = root.querySelector('[data-meeting-person-filter]');
        if (people) {
            people.addEventListener('input', () => {
                const term = people.value.trim().toLocaleLowerCase('ru');
                root.querySelectorAll('[data-meeting-person]').forEach(person => {
                    person.hidden = !person.textContent.toLocaleLowerCase('ru').includes(term);
                });
            });
        }
        const picker = root.querySelector('[data-recipient-filter]');
        if (picker) { picker.addEventListener('change', event => {
            if (event.target.name === 'departmentid') { picker.elements.positionid.value = ''; picker.elements.assigneeid.value = '0'; }
            if (event.target.name === 'positionid') { picker.elements.assigneeid.value = '0'; }
            picker.submit();
        }); }
        const type = root.querySelector('select[name="kind"]');
        function updateKind() {
            root.querySelectorAll('[data-kind]').forEach(el => {
                el.hidden = el.dataset.kind !== type.value;
                el.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = el.hidden; });
            });
            const repeat = root.querySelector('[data-repeat-section]');
            if (repeat) {
                repeat.hidden = type.value === 'retraining';
                repeat.querySelectorAll('input,textarea').forEach(input => { input.disabled = repeat.hidden; });
            }
        }
        if (type) { type.addEventListener('change', updateKind); updateKind(); }
        root.querySelectorAll('[data-task-assignment]').forEach(enableAssignment);
        root.addEventListener('click', event => {
            const button = event.target.closest('button');
            if (!button) { return; }
            if (button.hasAttribute('data-add-brief-field')) {
                const container = root.querySelector('[data-brief-fields]');
                if (container.children.length < 24) {
                    const template = root.querySelector('[data-brief-field]');
                    container.appendChild(template.content.cloneNode(true));
                    container.lastElementChild.querySelector('input').focus();
                }
            }
            if (button.hasAttribute('data-remove-brief-field')) {
                const row = button.closest('.uw-brief-field');
                if (row.parentElement.children.length > 1) { row.remove(); }
            }
            if (button.hasAttribute('data-add-field') || button.hasAttribute('data-add-level')) {
                const isField = button.hasAttribute('data-add-field');
                const container = root.querySelector(isField ? '[data-builder]' : '[data-rule-levels]');
                const maximum = isField ? 40 : 5;
                if (container.children.length >= maximum) { return; }
                const template = root.querySelector(isField ? '[data-field-template]' : '[data-level-template]');
                const fragment = template.content.cloneNode(true);
                const key = fragment.querySelector('input[name="fieldkey[]"]');
                if (key) { key.value = 'f_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10); }
                container.appendChild(fragment);
                container.lastElementChild.querySelector('input:not([type="hidden"]),select').focus();
            }
            if (button.hasAttribute('data-remove-row')) {
                const row = button.closest('.uw-builder-row');
                if (row.parentElement.hasAttribute('data-builder') && row.parentElement.children.length <= 1) { return; }
                row.remove();
            }
            if (button.hasAttribute('data-move-up')) {
                const row = button.closest('.uw-builder-row');
                if (row.previousElementSibling) { row.parentElement.insertBefore(row, row.previousElementSibling); }
            }
        });
        root.querySelectorAll('input[type="file"][accept]').forEach(input => {
            const preview = input.closest('form').querySelector('[data-photo-preview]');
            if (!preview) { return; }
            let urls = [];
            input.addEventListener('change', () => {
                urls.forEach(url => URL.revokeObjectURL(url)); urls = []; preview.replaceChildren();
                Array.from(input.files).slice(0, 5).forEach(file => {
                    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { return; }
                    const img = document.createElement('img');
                    const url = URL.createObjectURL(file); urls.push(url); img.src = url; img.alt = file.name;
                    preview.appendChild(img);
                });
            });
            window.addEventListener('pagehide', () => urls.forEach(url => URL.revokeObjectURL(url)), {once: true});
        });
        const panel = root.querySelector('#uw-editor') || root.querySelector('#uw-detail');
        if (panel && location.hash !== '#uw-discussion') { panel.scrollIntoView({block: 'start', behavior: 'auto'}); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
