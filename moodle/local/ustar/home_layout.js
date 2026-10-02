(function(){
    'use strict';
    function init(){
        var root=document.querySelector('[data-home-layout]');if(!root||root.dataset.initialized==='1')return;root.dataset.initialized='1';
        var data=JSON.parse(root.dataset.settings||'{}'),revision=data.revision||0,settings=data.items||{};
        var blocks=Array.from(root.children).filter(function(el){return el.dataset.homeBlock;});
        var controls=document.querySelector('[data-home-controls]'),status=document.querySelector('[data-home-status]'),queue=Promise.resolve(),failed=false,pending=0,dragging=null;
        function defaults(id,index){return {order:index,span:['tasks','checklists','notes'].includes(id)?4:12,visible:!['team','competition','achievements'].includes(id),style:'plain'};}
        blocks.forEach(function(b,i){settings[b.dataset.homeBlock]=Object.assign(defaults(b.dataset.homeBlock,i),settings[b.dataset.homeBlock]||{});});
        function order(){return blocks.slice().sort(function(a,b){return settings[a.dataset.homeBlock].order-settings[b.dataset.homeBlock].order;});}
        function apply(){order().forEach(function(b){var s=settings[b.dataset.homeBlock];b.hidden=!s.visible;b.dataset.span=String(s.span);b.dataset.style=s.style;root.append(b);});}
        function save(){if(failed)return;pending++;status.textContent='Сохраняем…';var snapshot=JSON.stringify(settings);
            queue=queue.then(async function(){if(failed){pending--;return;}try{var response=await fetch(root.dataset.url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:new URLSearchParams({layout:snapshot,revision:String(revision),sesskey:root.dataset.sesskey})});var result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Не удалось сохранить настройки.');revision=result.revision;pending--;status.textContent=pending?'Сохраняем…':'Ваш вид главной сохранён';}catch(e){failed=true;pending--;status.textContent=e.message+' Обновите страницу.';controls.querySelectorAll('input,select,button').forEach(function(el){el.disabled=true;});}});
        }
        apply();if(root.dataset.editable!=='1'&&root.dataset.editable!=='true')return;
        function move(id,delta){if(failed)return;var list=order(),index=list.findIndex(function(b){return b.dataset.homeBlock===id;}),target=index+delta;if(target<0||target>=list.length)return;var b=list.splice(index,1)[0];list.splice(target,0,b);list.forEach(function(el,i){settings[el.dataset.homeBlock].order=i;});apply();render();save();}
        function field(label,element){var l=document.createElement('label');l.textContent=label;l.append(element);return l;}
        function render(){controls.replaceChildren();order().forEach(function(b){var id=b.dataset.homeBlock,s=settings[id],row=document.createElement('div');row.className='u-home-control';
            var visible=document.createElement('input');visible.type='checkbox';visible.checked=s.visible;visible.addEventListener('change',function(){s.visible=visible.checked;apply();save();});row.append(field(b.dataset.homeLabel,visible));
            var span=document.createElement('select');[[4,'Треть ширины'],[6,'Половина'],[12,'Вся ширина']].forEach(function(p){span.add(new Option(p[1],p[0]));});span.value=String(s.span);span.addEventListener('change',function(){s.span=Number(span.value);apply();save();});row.append(field('Размер',span));
            var style=document.createElement('select');[['plain','Обычный'],['soft','Мягкий фон'],['accent','Акцент']].forEach(function(p){style.add(new Option(p[1],p[0]));});style.value=s.style;style.addEventListener('change',function(){s.style=style.value;apply();save();});row.append(field('Оформление',style));
            [-1,1].forEach(function(delta){var button=document.createElement('button');button.type='button';button.className='uw-btn';button.textContent=delta<0?'↑':'↓';button.setAttribute('aria-label',(delta<0?'Поднять ':'Опустить ')+b.dataset.homeLabel);button.addEventListener('click',function(){move(id,delta);});row.append(button);});controls.append(row);
        });}
        blocks.forEach(function(b){var grip=document.createElement('button');grip.type='button';grip.className='u-home-grip';grip.draggable=true;grip.textContent='⠿';grip.title='Перетащите блок или используйте настройку порядка';grip.setAttribute('aria-label','Переместить '+b.dataset.homeLabel);b.prepend(grip);
            grip.addEventListener('dragstart',function(e){if(failed){e.preventDefault();return;}dragging=b;e.dataTransfer.setData('text/plain',b.dataset.homeBlock);});b.addEventListener('dragover',function(e){if(dragging){e.preventDefault();}});b.addEventListener('drop',function(e){e.preventDefault();if(!dragging||dragging===b||failed)return;var list=order().filter(function(el){return el!==dragging;}),index=list.indexOf(b);list.splice(index,0,dragging);list.forEach(function(el,i){settings[el.dataset.homeBlock].order=i;});dragging=null;apply();render();save();});grip.addEventListener('dragend',function(){dragging=null;});
        });
        document.querySelector('[data-home-reset]').addEventListener('click',function(){if(failed)return;blocks.forEach(function(b,i){settings[b.dataset.homeBlock]=defaults(b.dataset.homeBlock,i);});apply();render();save();});
        window.addEventListener('beforeunload',function(e){if(pending||failed){e.preventDefault();e.returnValue='';}});render();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
}());
