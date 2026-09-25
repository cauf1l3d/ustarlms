(function () {
    'use strict';
    const body = document.body;
    if (!body || body.dataset.ustarScormInlineBound) { return; }
    const cmid = Number(body.dataset.ustarScormCmid || 0);
    const launchid = body.dataset.ustarScormLaunch || '';
    const statusUrl = body.dataset.ustarScormStatusUrl;
    if (!cmid || !launchid || !statusUrl) { return; }
    body.dataset.ustarScormInlineBound = '1';
    body.classList.add('ustar-scorm-inline');
    const fitStyle = document.createElement('style');
    fitStyle.id = 'ustar-scorm-fit-runtime';
    fitStyle.textContent = "/* Route-only embedded SCORM. Keep the Moodle DOM/API alive, remove outer presentation. */\nbody.ustar-scorm-inline [data-ustar-scorm-return],\nbody.ustar-scorm-inline .ustar-scorm-legacy,\nbody.ustar-scorm-inline .u-activity-context,\nbody.ustar-scorm-inline .activity-header,\nbody.ustar-scorm-inline .activity-navigation,\nbody.ustar-scorm-inline .tertiary-navigation,\nbody.ustar-scorm-inline .scorm-exitbar,\nbody.ustar-scorm-inline #scorm_toc_toggle,\nbody.ustar-scorm-inline #scorm_navpanel { display:none !important; }\nbody#page-mod-scorm-player.ustar-scorm-inline .u-main {\n    height:var(--ustar-scorm-height,calc(100dvh - 64px)) !important;\n    min-height:0 !important; padding:0 !important; margin-top:0 !important; margin-bottom:0 !important;\n    overflow:hidden !important;\n}\nbody#page-mod-scorm-player.ustar-scorm-inline .u-main > .u-container,\nbody#page-mod-scorm-player.ustar-scorm-inline #page-content,\nbody#page-mod-scorm-player.ustar-scorm-inline #region-main-box,\nbody#page-mod-scorm-player.ustar-scorm-inline #region-main,\nbody#page-mod-scorm-player.ustar-scorm-inline [role=\"main\"],\nbody#page-mod-scorm-player.ustar-scorm-inline #scorm_layout,\nbody#page-mod-scorm-player.ustar-scorm-inline #scormpage,\nbody#page-mod-scorm-player.ustar-scorm-inline #scorm_content,\nbody#page-mod-scorm-player.ustar-scorm-inline #scorm_box,\nbody#page-mod-scorm-player.ustar-scorm-inline #scorm_object {\n    box-sizing:border-box !important; width:100% !important; max-width:none !important;\n    height:100% !important; min-height:0 !important; max-height:none !important;\n    margin:0 !important; padding:0 !important; border:0 !important; border-radius:0 !important;\n}\nbody#page-mod-scorm-player.ustar-scorm-inline #scorm_content,\nbody#page-mod-scorm-player.ustar-scorm-inline #scorm_object { display:block !important; }\n/* The TOC stays available to Moodle's initialization and SCORM navigation code. */\nbody#page-mod-scorm-player.ustar-scorm-inline #scorm_toc {\n    position:absolute !important; width:0 !important; min-width:0 !important;\n    height:0 !important; overflow:hidden !important; visibility:hidden !important;\n}\n";
    document.head.appendChild(fitStyle);
    document.querySelectorAll('[data-ustar-scorm-return]').forEach(el => el.remove());
    let timer, busy = false, acknowledged = false, finished = false, failures = 0, control = null;
    const documents = new Map(), labels = new WeakMap();
    const controls = 'button,input[type="button"],input[type="submit"],a,[role="button"]';
    const finishText = /^(?:изучено|изучил|материал изучен|завершить(?: курс| обучение| изучение)?|finish|complete)(?:\s*[✓✔→.!]*)$/iu;
    const studiedText = /^(?:изучено|изучен|материал изучен|завершено|курс завершён|курс завершен|completed)(?:\s*[✓✔→.!]*)$/iu;
    function label(el) { return (el.value || el.textContent || el.getAttribute('aria-label') || '').trim(); }
    function localUrl(value) {
        const url = new URL(value, location.href);
        if (url.origin !== location.origin) { throw new Error('Invalid destination'); }
        return url;
    }
    function schedule(delay = 1200) { clearTimeout(timer); if (!finished) { timer = setTimeout(check, delay); } }
    function accept(el) { acknowledged = true; control = el; schedule(150); }
    function inspect(doc, depth = 0) {
        if (!doc || depth > 5 || finished) { return; }
        if (!documents.has(doc)) {
            const click = event => {
                const el = event.target.closest && event.target.closest(controls);
                if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') { return; }
                // Run the course's own handler normally; it owns SCORM completion.
                if (finishText.test(label(el))) {
                    control = el;
                    if (studiedText.test(label(el))) { accept(el); }
                    else { schedule(150); }
                }
            };
            doc.addEventListener('click', click, true);
            const Observer = doc.defaultView && doc.defaultView.MutationObserver || MutationObserver;
            const observer = new Observer(() => { inspect(doc, depth); if (doc === document) { fit(); } });
            observer.observe(doc.documentElement, {subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['value','aria-label','disabled','aria-disabled']});
            const load = () => inspect(doc, depth);
            doc.addEventListener('load', load, true);
            documents.set(doc, {observer,click,load});
        }
        doc.querySelectorAll(controls).forEach(el => {
            const now = label(el), before = labels.get(el);
            if (before !== undefined && before !== now && studiedText.test(now) && finishText.test(before)) { accept(el); }
            labels.set(el, now);
        });
        doc.querySelectorAll('iframe,frame,object').forEach(frame => {
            try { if (frame.contentDocument) { inspect(frame.contentDocument, depth + 1); } }
            catch (e) { /* Server completion remains mandatory; no cross-origin access. */ }
        });
    }
    function fit() {
        const header = document.querySelector('.u-topbar');
        const bottom = document.querySelector('.u-bottomnav');
        const top = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
        const bottomHeight = bottom && getComputedStyle(bottom).display !== 'none' ? bottom.getBoundingClientRect().height : 0;
        body.style.setProperty('--ustar-scorm-height', Math.max(160, (window.visualViewport ? window.visualViewport.height : window.innerHeight) - top - bottomHeight) + 'px');
        // These wrappers also receive inline critical geometry: old theme !important rules
        // and cached linked styles must not preserve 720px minimums or top padding.
        const main = document.querySelector('.u-main');
        const playerHeight = body.style.getPropertyValue('--ustar-scorm-height');
        const geometry = {height:playerHeight, 'min-height':'0', 'max-height':'none', width:'100%', 'max-width':'none', margin:'0', padding:'0', border:'0', 'box-sizing':'border-box'};
        if (main) {
            ['padding','margin-top','margin-bottom','min-height'].forEach(p => main.style.setProperty(p,'0','important'));
            main.style.setProperty('height',body.style.getPropertyValue('--ustar-scorm-height'),'important');
            main.style.setProperty('overflow','hidden','important');
            main.querySelectorAll('.u-container,#page-content,#region-main-box,#region-main,[role="main"],#scorm_layout,#scormpage,#scorm_content,#scorm_box,#scorm_object,iframe[id^=scorm],object[id^=scorm]').forEach(el => {
                Object.entries(geometry).forEach(([p,v]) => el.style.setProperty(p,v,'important'));
            });
            main.querySelectorAll('button,input[type="submit"],input[type="button"],a').forEach(el => {
                const text = label(el).replace(/\s+/g,' ').toLowerCase();
                if (['перейти на главную страницу курса','вернуться на главную страницу курса','exit activity','return to course'].includes(text)) {
                    const wrapper = el.closest('.singlebutton,.scorm-exitbar') || el;
                    wrapper.style.setProperty('display','none','important');
                }
            });
        }
        document.querySelectorAll('#scorm_toc_toggle,#scorm_navpanel').forEach(el => el.style.setProperty('display','none','important'));
        // Hide only Moodle's outer course-return controls, never controls inside the SCO.
        document.querySelectorAll('main a[href],main form[action]').forEach(el => {
            try {
                const u = new URL(el.getAttribute('href') || el.getAttribute('action'), location.href);
                if (u.origin === location.origin && /\/course\/view\.php$/.test(u.pathname)) {
                    (el.closest('.singlebutton') || el).classList.add('ustar-scorm-legacy');
                }
            } catch (e) { /* Ignore non-URL controls. */ }
        });
    }
    async function request(confirm, sesskey = '') {
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 10000);
        const url = localUrl(statusUrl), options = {credentials:'same-origin',cache:'no-store',signal:controller.signal};
        if (confirm) {
            options.method = 'POST';
            options.body = new URLSearchParams({cmid:String(cmid),launchid,confirm:'1',acknowledged:'1',sesskey});
        } else { url.searchParams.set('cmid',String(cmid)); url.searchParams.set('launchid',launchid); }
        try {
            const response = await fetch(url.href, options);
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            const data = await response.json();
            if (data.error) { throw new Error('Confirmation failed'); }
            return data;
        } finally { clearTimeout(timeout); }
    }
    async function check() {
        clearTimeout(timer);
        if (finished) { return; }
        if (busy || document.hidden) { schedule(); return; }
        inspect(document); fit();
        if (!acknowledged) { schedule(); return; }
        busy = true;
        try {
            const status = await request(false);
            if (finished) { return; }
            if (!status.active) {
                if (control) { control.title = 'Запуск курса изменился. Откройте материал из маршрута заново.'; }
                return;
            }
            if (!(status.ready || status.confirmed)) { return; }
            const result = await request(true, status.sesskey);
            if (finished) { return; }
            if (!result.confirmed || !result.targeturl) { throw new Error('Not confirmed'); }
            const target = localUrl(result.targeturl);
            finished = true; clearTimeout(timer);
            window.location.assign(target.href);
        } catch (e) {
            failures++;
            if (control) { control.title = 'Результат сохраняется. При восстановлении связи переход выполнится автоматически.'; }
        } finally { busy = false; schedule(Math.min(15000, 1200 * Math.pow(2, Math.min(failures, 4)))); }
    }
    function stop() {
        finished = true; clearTimeout(timer);
        documents.forEach((entry,doc) => { entry.observer.disconnect(); doc.removeEventListener('click',entry.click,true); doc.removeEventListener('load',entry.load,true); });
        documents.clear();
    }
    window.addEventListener('pagehide',stop);
    window.addEventListener('pageshow',() => { finished = false; inspect(document); fit(); schedule(); });
    window.addEventListener('resize',fit);
    if (window.visualViewport) { window.visualViewport.addEventListener('resize',fit); }
    document.addEventListener('visibilitychange',() => { if (!document.hidden) { schedule(100); } });
    inspect(document); fit(); schedule();
})();
