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

        var audienceOptions = document.querySelector('[data-feed-audience-options]');
        if (audienceOptions) {
            var modes = document.querySelectorAll('input[name="audiencemode"]');
            var updateAudience = function() {
                var selected = document.querySelector('input[name="audiencemode"]:checked');
                audienceOptions.hidden = !selected || selected.value !== 'departments';
                audienceOptions.querySelectorAll('input').forEach(function(input) {
                    input.disabled = audienceOptions.hidden;
                });
            };
            modes.forEach(function(mode) {
                mode.addEventListener('change', updateAudience);
            });
            updateAudience();
            audienceOptions.closest('form').addEventListener('submit', function(event) {
                var selected = document.querySelector('input[name="audiencemode"]:checked');
                if (publisher.value === 'academy' && selected && selected.value === 'departments' &&
                        !audienceOptions.querySelector('input:checked')) {
                    event.preventDefault();
                    audienceOptions.querySelector('input').focus();
                    var warning = audienceOptions.querySelector('[role="alert"]');
                    if (!warning) {
                        warning = document.createElement('p');
                        warning.setAttribute('role', 'alert');
                        audienceOptions.appendChild(warning);
                    }
                    warning.textContent = 'Выберите хотя бы одно подразделение.';
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
                    if (!result.ok || typeof result.count !== 'number') {
                        throw new Error(result.message || 'Не удалось обновить реакцию.');
                    }
                    button.value = result.liked ? 'unlike' : 'like';
                    button.setAttribute('aria-pressed', result.liked ? 'true' : 'false');
                    button.querySelector('[aria-hidden]').textContent = result.liked ? '♥' : '♡';
                    button.querySelector('[data-feed-count]').textContent = result.count;
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
