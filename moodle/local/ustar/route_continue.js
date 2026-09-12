(function () {
    'use strict';
    function init() {
        const wrap = document.querySelector('[data-ustar-route-continue="post-v1"]');
        const canonical = wrap && wrap.querySelector('form');
        const key = canonical ? canonical.elements.sesskey.value : (window.M && M.cfg && M.cfg.sesskey);
        if (!key) { return; }
        const links = Array.from(document.querySelectorAll('a[href]')).filter(a => {
            try {
                const u = new URL(a.href, location.href);
                return u.origin === location.origin && /\/local\/ustar\/continue\.php$/.test(u.pathname)
                    && /^\d+$/.test(u.searchParams.get('cmid') || '');
            } catch (e) { return false; }
        });
        // Older Page content contains a literal link without a session token. Replace the
        // visible control, not the DB content, so cached pages never contain another user's key.
        const first = links[0];
        if (first) {
            const u = new URL(first.href, location.href);
            const form = canonical || document.createElement('form');
            if (!canonical) {
                form.method = 'post'; form.action = u.origin + u.pathname;
                for (const [name, value] of [['cmid', u.searchParams.get('cmid')], ['sesskey', key]]) {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
                }
                const button = document.createElement('button');
                button.type = 'submit'; button.className = 'btn btn-primary ustar-route-continue-button';
                button.textContent = 'Изучено, продолжить →'; form.appendChild(button);
            }
            first.replaceWith(form);
            links.slice(1).forEach(a => a.remove());
            if (wrap) { wrap.remove(); }
        }
        const form = canonical || (first && document.querySelector('form[action$="/local/ustar/continue.php"]'));
        if (form && !form.dataset.ustarBound) {
            form.dataset.ustarBound = '1';
            form.addEventListener('submit', () => { form.querySelector('button').disabled = true; });
            window.addEventListener('pageshow', () => { form.querySelector('button').disabled = false; });
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
