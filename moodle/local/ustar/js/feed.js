/** Small progressive enhancements for the Moodle-native feed. */
(function() {
    'use strict';

    function ready() {
        var publisher = document.getElementById('feed-publisher');
        if (publisher) {
            var updateFields = function() {
                document.querySelectorAll('[data-feed-publisher-field]').forEach(function(field) {
                    field.hidden = field.dataset.feedPublisherField !== publisher.value;
                });
            };
            publisher.addEventListener('change', updateFields);
            updateFields();
        }

        var audience = document.querySelector('[data-feed-audience]');
        if (publisher && audience) {
            var modes = audience.querySelectorAll('input[name="audiencemode"]');
            var panels = audience.querySelectorAll('[data-feed-audience-options]');
            var updateAudience = function() {
                audience.hidden = publisher.value === 'department';
                modes.forEach(function(mode) {
                    var label = mode.closest('[data-feed-mode-for]');
                    var allowed = label.dataset.feedModeFor === 'both' ||
                        label.dataset.feedModeFor === publisher.value;
                    if (publisher.value === 'academy' && mode.value !== 'all' &&
                            audience.dataset.feedCanAcademyAudience !== '1') { allowed = false; }
                    label.hidden = !allowed;
                    mode.disabled = !allowed || audience.hidden;
                    if (!allowed) { mode.checked = false; }
                });
                if (!audience.hidden && !audience.querySelector('input[name="audiencemode"]:checked')) {
                    audience.querySelector('input[value="all"]').checked = true;
                }
                var selected = audience.querySelector('input[name="audiencemode"]:checked');
                panels.forEach(function(panel) {
                    panel.hidden = audience.hidden || !selected ||
                        panel.dataset.feedAudienceOptions !== selected.value;
                    panel.querySelectorAll('input').forEach(function(input) { input.disabled = panel.hidden; });
                });
            };
            publisher.addEventListener('change', updateAudience);
            modes.forEach(function(mode) { mode.addEventListener('change', updateAudience); });
            updateAudience();
            var people = audience.querySelector('[data-feed-audience-options="people"]');
            var search = people.querySelector('input[type="search"]');
            var results = people.querySelector('[data-feed-people-results]');
            var selectedPeople = people.querySelector('[data-feed-people-selected]');
            var timer, requestNumber = 0;
            search.addEventListener('input', function() {
                clearTimeout(timer);
                var query = search.value.trim(), current = ++requestNumber;
                results.replaceChildren();
                if (query.length < 2) { return; }
                timer = setTimeout(function() {
                    fetch(people.dataset.searchUrl + '?q=' + encodeURIComponent(query),
                        {credentials: 'same-origin'}).then(function(response) {
                        if (!response.ok) { throw new Error('Поиск недоступен.'); }
                        return response.json();
                    }).then(function(data) {
                        if (current !== requestNumber) { return; }
                        results.replaceChildren();
                        if (!data.people.length) { results.textContent = 'Сотрудники не найдены.'; }
                        data.people.forEach(function(person) {
                            if (selectedPeople.querySelector('input[value="' + person.id + '"]')) { return; }
                            var button = document.createElement('button');
                            button.type = 'button'; button.className = 'u-feed__person-option';
                            button.textContent = person.name;
                            button.addEventListener('click', function() {
                                if (selectedPeople.querySelectorAll('input').length >= 50) { return; }
                                var chip = document.createElement('span');
                                chip.className = 'u-feed__person-chip'; chip.textContent = person.name + ' ';
                                var input = document.createElement('input');
                                input.type = 'hidden'; input.name = 'people[]'; input.value = person.id;
                                var remove = document.createElement('button');
                                remove.type = 'button'; remove.textContent = '×';
                                remove.setAttribute('aria-label', 'Убрать ' + person.name);
                                remove.addEventListener('click', function() { chip.remove(); });
                                chip.append(input, remove); selectedPeople.appendChild(chip);
                                button.remove();
                            });
                            results.appendChild(button);
                        });
                    }).catch(function(error) {
                        if (current === requestNumber) { results.textContent = error.message; }
                    });
                }, 250);
            });
            search.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') { event.preventDefault(); }
            });
            audience.closest('form').addEventListener('submit', function(event) {
                if (publisher.value === 'department') { return; }
                var mode = audience.querySelector('input[name="audiencemode"]:checked');
                var panel = mode && audience.querySelector('[data-feed-audience-options="' + mode.value + '"]');
                if (panel && !panel.querySelector('input:checked:not(:disabled), input[type="hidden"]:not(:disabled)')) {
                    event.preventDefault();
                    var warning = panel.querySelector('[role="alert"]');
                    if (!warning) {
                        warning = document.createElement('p'); warning.setAttribute('role', 'alert');
                        panel.appendChild(warning);
                    }
                    warning.textContent = 'Выберите хотя бы одного адресата.';
                    panel.querySelector('input:not(:disabled)')?.focus();
                }
            });
        }

        document.querySelectorAll('[data-feed-like]').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!window.fetch || !window.FormData) {
                    return;
                }
                event.preventDefault();
                var button = form.querySelector('button[name="action"]');
                var previous = button.value;
                var data = new FormData(form);
                data.set('action', previous);
                data.set('ajaxlike', '1');
                button.disabled = true;

                fetch(form.action || window.location.href, {
                    method: 'POST', body: data, credentials: 'same-origin',
                    headers: {'Accept': 'application/json'}
                }).then(function(response) {
                    if (!response.ok || !response.headers.get('Content-Type') ||
                            !response.headers.get('Content-Type').includes('application/json')) {
                        throw new Error('Не удалось обновить реакцию.');
                    }
                    return response.json();
                }).then(function(result) {
                    if (!result.ok || !Number.isSafeInteger(Number(result.count))) {
                        throw new Error(result.message || 'Не удалось обновить реакцию.');
                    }
                    button.value = result.liked ? 'unlike' : 'like';
                    button.setAttribute('aria-pressed', result.liked ? 'true' : 'false');
                    button.querySelector('[aria-hidden]').textContent = result.liked ? '♥' : '♡';
                    button.querySelector('[data-feed-count]').textContent = Number(result.count);
                    var status = form.querySelector('[role="status"]');
                    if (status) {
                        status.remove();
                    }
                }).catch(function(error) {
                    var status = form.querySelector('[role="status"]');
                    if (!status) {
                        status = document.createElement('span');
                        status.className = 'u-feed__action-error';
                        status.setAttribute('role', 'status');
                        form.appendChild(status);
                    }
                    status.textContent = error.message || 'Не удалось обновить реакцию.';
                }).finally(function() {
                    button.disabled = false;
                });
            });
        });

        document.querySelectorAll('[data-feed-discussion-toggle]').forEach(function(link) {
            link.addEventListener('click', function(event) {
                var panel = document.getElementById(link.dataset.feedDiscussionToggle);
                if (!panel) { return; }
                event.preventDefault();
                panel.hidden = !panel.hidden;
                link.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                if (!panel.hidden) { panel.scrollIntoView({block: 'nearest'}); }
            });
        });
        if (window.location.hash) {
            var target = document.getElementById(window.location.hash.slice(1));
            if (target) {
                if (target.tagName.toLowerCase() === 'details') {
                    target.open = true;
                }
                target.scrollIntoView({block: 'nearest'});
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
}());
