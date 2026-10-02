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
                button.type = 'submit'; button.className = 'u-btn u-btn--primary u-btn--large ustar-route-continue-button';
                button.textContent = 'Изучено, продолжить →'; form.appendChild(button);
            }
            if (canonical) { first.remove(); } else { first.replaceWith(form); }
            links.slice(1).forEach(a => a.remove());
            if (wrap && !canonical) { wrap.remove(); }
        }
        const form = canonical || (first && document.querySelector('form[action$="/local/ustar/continue.php"]'));
        // USTAR UX RC2: canonical visual placement; POST/sesskey logic is unchanged.
        if (form) {
            form.classList.add('ustar-route-continue-form');
            form.style.display = 'flex';
            form.style.justifyContent = 'flex-end';
            form.style.width = '100%';
            form.style.margin = '24px 0';
            const visualButton = form.querySelector('button');
            if (visualButton) {
                visualButton.classList.remove('btn', 'btn-primary');
                visualButton.classList.add('u-btn', 'u-btn--primary', 'u-btn--large', 'ustar-route-continue-button');
            }
        }
        if (form && !form.dataset.ustarBound) {
            form.dataset.ustarBound = '1';
            form.addEventListener('submit', () => { form.querySelector('button').disabled = true; });
            window.addEventListener('pageshow', () => { form.querySelector('button').disabled = false; });
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();


/* USTAR_CONTINUE_VISUAL_20260917 */
(function () {
    'use strict';

    function applyUstarContinueVisual() {

        /*
         * Exact visual contract used by normal USTAR
         * u-btn u-btn--primary controls.
         *
         * This does NOT change:
         * - form action
         * - POST
         * - cmid
         * - sesskey
         * - submit handlers
         * - navigation
         */

        if (!document.getElementById(
            'ustar-continue-visual-style'
        )) {
            const style =
                document.createElement('style');

            style.id =
                'ustar-continue-visual-style';

            style.textContent = `
                .ustar-route-continue-button {
                    display: inline-flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    gap: 7px !important;

                    min-height: 40px !important;
                    padding: 8px 13px !important;

                    border:
                        1px solid
                        color-mix(
                            in srgb,
                            var(--u-brand) 74%,
                            var(--u-border)
                        ) !important;

                    border-radius: 11px !important;

                    background:
                        var(--u-brand) !important;

                    color:
                        var(--u-text-on-brand) !important;

                    font-family:
                        var(--u-font-ui) !important;

                    font-weight: 750 !important;
                    line-height: 1.2 !important;

                    text-decoration: none !important;
                    text-shadow: none !important;
                    box-shadow: none !important;

                    cursor: pointer !important;
                }

                .ustar-route-continue-button:hover {
                    background:
                        var(--u-brand-strong) !important;

                    color:
                        var(--u-text-on-brand) !important;

                    text-decoration: none !important;
                }

                .ustar-route-continue-button:active {
                    transform: translateY(1px);
                }

                form[action$="/local/ustar/continue.php"] {
                    display: flex !important;
                    justify-content: flex-end !important;
                    align-items: center !important;

                    width: 100% !important;
                    max-width: none !important;

                    margin: 24px 0 8px !important;
                    padding: 0 !important;

                    text-align: right !important;
                }

                [data-ustar-route-continue="post-v1"] {
                    display: flex !important;
                    justify-content: flex-end !important;

                    width: 100% !important;
                    max-width: none !important;
                }
            `;

            document.head.appendChild(style);
        }

        document
            .querySelectorAll(
                'form[action$="/local/ustar/continue.php"]'
            )
            .forEach(form => {

                const button =
                    form.querySelector(
                        'button[type="submit"]'
                    );

                if (!button) {
                    return;
                }

                /*
                 * Remove Bootstrap presentation only.
                 * Functional attributes stay untouched.
                 */
                button.classList.remove(
                    'btn',
                    'btn-primary',
                    'u-btn--large'
                );

                button.classList.add(
                    'u-btn',
                    'u-btn--primary',
                    'ustar-route-continue-button'
                );
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            applyUstarContinueVisual
        );
    } else {
        applyUstarContinueVisual();
    }
})();
