(function(){'use strict';
 const course=window.COURSE,pages=course.pages,$=id=>document.getElementById(id);
 let api=null,started=false,finished=false,current=0,confirmed={},completed=false,saveError=false;
 const startedAt=Date.now(),key='rc-v3-'+course.code+'-'+course.revisionId;
 const ok=v=>v===true||v==='true';
 function find(start){let w=start;for(let i=0;i<12&&w;i++){try{if(w.API)return w.API;if(w.parent===w)break;w=w.parent}catch(e){break}}return null}
 try{api=find(window);if(!api&&window.opener)api=find(window.opener)}catch(e){}
 function valid(i){return Number.isInteger(i)&&i>=0&&i<pages.length}
 function restore(saved){if(saved&&saved.v===2&&saved.revisionId===course.revisionId){current=valid(saved.page)?saved.page:0;(saved.read||[]).forEach(i=>{if(valid(i))confirmed[i]=true});completed=!!saved.completed}}
 if(api){try{started=ok(api.LMSInitialize(''));if(started){let saved={};try{saved=JSON.parse(api.LMSGetValue('cmi.suspend_data')||'{}')}catch(e){}restore(saved);const bookmark=Number(api.LMSGetValue('cmi.core.lesson_location'));if(saved.revisionId===course.revisionId&&valid(bookmark))current=bookmark;const status=api.LMSGetValue('cmi.core.lesson_status');completed=saved.revisionId===course.revisionId&&(status==='completed'||status==='passed');if(!completed)api.LMSSetValue('cmi.core.lesson_status','incomplete')}else saveError=true}catch(e){saveError=true}}
 else{try{restore(JSON.parse(localStorage.getItem(key)||'{}'))}catch(e){}}
 function state(){return {v:2,page:current,read:Object.keys(confirmed).map(Number),completed,revisionId:course.revisionId}}
 function persist(){if(api){if(!started)return false;try{const a=ok(api.LMSSetValue('cmi.core.lesson_location',String(current)));const b=ok(api.LMSSetValue('cmi.suspend_data',JSON.stringify(state())));const c=ok(api.LMSCommit(''));saveError=!(a&&b&&c);return !saveError}catch(e){saveError=true;return false}}try{localStorage.setItem(key,JSON.stringify(state()));saveError=false;return true}catch(e){saveError=true;return false}}
 function notice(){if(saveError)$('lms').textContent='Не удалось сохранить прогресс. Повторите действие; при повторной ошибке обратитесь к администратору.';else $('lms').textContent=api?'Прогресс сохраняется в Академии.':'Локальный просмотр: результат хранится только в этом браузере и не передается в Академию.'}
 function show(message){const p=pages[current];$('title').textContent=p.title;$('title').removeAttribute('style');if(p.titleHTML)$('title').innerHTML=p.titleHTML;if(p.titleStyle&&['left','center','right','justify'].includes(p.titleStyle.textAlign))$('title').style.textAlign=p.titleStyle.textAlign;$('chapter').textContent=course.code+' / '+(p.kind==='lesson'?'Учебный материал':p.kind==='intro'?'Начало обучения':'Самопроверка');$('content').innerHTML=p.html;$('counter').textContent='Экран '+(current+1)+' из '+pages.length+' · отмечено изученными '+Object.keys(confirmed).length;
 $('progress').value=Object.keys(confirmed).length/pages.length*100;$('progress').setAttribute('aria-valuetext',Object.keys(confirmed).length+' из '+pages.length);
 $('source').textContent=p.source_text&&p.source_text.trim()?p.source_text:'Учебное вступление / самопроверка по материалам курса.';
 $('back').disabled=current===0;$('next').disabled=completed&&current===pages.length-1;$('next').textContent=current===pages.length-1?(completed?'Курс завершен':'Завершить курс'):'Изучено — дальше';
 $('status').textContent=message||(completed?'Курс завершен. Результат аттестации учитывается отдельно.':'Изучите материал, затем отметьте экран кнопкой «Изучено — дальше».');
 document.querySelectorAll('[data-page]').forEach(b=>{const i=Number(b.dataset.page);b.classList.toggle('active',i===current);b.setAttribute('aria-current',i===current?'step':'false');b.querySelector('.mark').textContent=confirmed[i]?'✓':''});
 document.querySelectorAll('[data-image]').forEach(b=>b.addEventListener('click',()=>{$('zoomImage').src=b.dataset.image;$('zoomImage').alt=b.querySelector('img').alt;$('zoom').showModal()}));
 persist();notice();window.scrollTo({top:0,behavior:'instant'});$('title').focus({preventScroll:true});
 }
 $('back').addEventListener('click',()=>{if(current>0){current--;show()}});
 $('next').addEventListener('click',()=>{confirmed[current]=true;if(current<pages.length-1){current++;show();return}const missing=pages.findIndex((_,i)=>!confirmed[i]);if(missing>=0){current=missing;show('Этот экран еще не отмечен изученным. Пройдите оставшиеся разделы.');return}
 if(api){let success=false;try{success=started&&ok(api.LMSSetValue('cmi.core.lesson_status','completed'))&&ok(api.LMSCommit(''))}catch(e){}if(!success){saveError=true;notice();$('status').textContent='Завершение не подтверждено Академией. Нажмите «Завершить курс» еще раз.';return}}
 completed=true;show();});
 document.querySelectorAll('[data-page]').forEach(b=>b.addEventListener('click',()=>{current=Number(b.dataset.page);show()}));
 $('closeZoom').addEventListener('click',()=>$('zoom').close());
 $('zoom').addEventListener('click',e=>{if(e.target===$('zoom'))$('zoom').close()});
 function finish(){if(finished)return;finished=true;if(started){try{const seconds=Math.floor((Date.now()-startedAt)/1000),hours=Math.floor(seconds/3600),mins=Math.floor(seconds/60)%60,secs=seconds%60;api.LMSSetValue('cmi.core.session_time',[hours,mins,secs].map(n=>String(n).padStart(2,'0')).join(':'));api.LMSSetValue('cmi.core.exit',completed?'':'suspend');persist();api.LMSFinish('')}catch(e){}}else persist()}
 window.addEventListener('pagehide',finish);window.addEventListener('beforeunload',finish);
 if(window.matchMedia('(min-width: 901px)').matches)$('menu').open=true;
 show();
})();
