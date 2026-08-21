define([], function() {
'use strict';
const init = function(id, version, saveurl, sesskey) {
    const textarea=document.getElementById('u-board-json'); const status=document.getElementById('u-board-status');
    const root=document.getElementById('u-dgm-root'); let currentVersion=Number(version)||1;

    // RC8.2: historical boards.php escaped documentjson with s() before Mustache
    // escaped it a second time. Those pages therefore exposed literal &quot; in
    // the textarea and DGM received invalid JSON. Parse raw JSON first and then
    // recover one legacy HTML-entity layer when necessary.
    const parseDocument=function(value){
        const raw=String(value||'').trim();
        if(!raw) return null;
        try { return JSON.parse(raw); } catch (_) {}
        if(raw.indexOf('&')!==-1){
            const helper=document.createElement('textarea');
            helper.innerHTML=raw;
            const decoded=helper.value;
            try { return JSON.parse(decoded); } catch (_) {}
        }
        return null;
    };

    const save=async function(json){ status.textContent='Сохраняем…'; const body=new URLSearchParams({id:String(id),version:String(currentVersion),json:json,sesskey:sesskey});
        const r=await fetch(saveurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString(),credentials:'same-origin'}); const d=await r.json(); if(!r.ok||!d.ok) throw new Error(d.error||'Ошибка сохранения'); currentVersion=Number(d.version); status.textContent='Сохранено'; };
    const button=document.getElementById('u-board-save');
    if(button&&textarea)button.addEventListener('click',()=>{
        const parsed=parseDocument(textarea.value);
        if(!parsed){ status.textContent='Некорректный JSON документа'; return; }
        textarea.value=JSON.stringify(parsed);
        save(textarea.value).catch(e=>status.textContent=e.message);
    });

    if (!root || !textarea) return;
    root.innerHTML='<div class="u-dgm-missing"><strong>Загрузка редактора…</strong><span>Подключаем локальный DGM runtime.</span></div>';

    const mountDgm=function(){
        if(!(window.USTARDGM && typeof window.USTARDGM.mount==='function')) return false;
        try {
            const doc=parseDocument(textarea.value);
            if(doc) textarea.value=JSON.stringify(doc);
            window.USTARDGM.mount(root,{document:doc,onChange:(next)=>{textarea.value=JSON.stringify(next);},onSave:(next)=>save(JSON.stringify(next))});
            root.classList.add('is-mounted');
            status.textContent='Редактор готов';
            return true;
        } catch(e) {
            status.textContent='DGM: '+(e && e.message ? e.message : 'ошибка запуска');
            root.innerHTML='<div class="u-dgm-missing"><strong>Ошибка запуска DGM</strong><span>Откройте Console для диагностики.</span></div>';
            return true;
        }
    };

    if (mountDgm()) return;
    let attempts=0;
    const timer=window.setInterval(function(){
        attempts++;
        if(mountDgm()) { window.clearInterval(timer); return; }
        if(attempts>=60) {
            window.clearInterval(timer);
            root.innerHTML='<div class="u-dgm-missing"><strong>DGM runtime не запустился</strong><span>Проверьте vendor/dgm и Console браузера.</span></div>';
        }
    },50);
}; return {init:init};
});
