(function () {
    'use strict';
    function init() {
        var root = document.querySelector('[data-notebook]');
        if (!root || root.dataset.initialized==='1') { return; }
        root.dataset.initialized='1';
        var canvas = root.querySelector('.un-canvas'), sizer = root.querySelector('.un-sizer');
        if (!canvas) { return; }
        var board = JSON.parse(root.dataset.board || '{}');
        var frames = Object.assign({},board.frames || {}), links = board.links || [];
        var revision = Number(root.dataset.revision), scale = 1, queue = Promise.resolve(), failed = false, pending = 0;
        var status = root.querySelector('[data-save-status]'), cards = Array.from(canvas.querySelectorAll('.un-card'));
        var lineLayer = canvas.querySelector('[data-links]'), frameLayer = canvas.querySelector('[data-frames]');
        var background = root.querySelector('[data-background]'), linking = null;
        root.classList.add('is-spatial');
        root.querySelectorAll('[data-board-tools]').forEach(function (el) { el.hidden = false; });
        function clamp(n, min, max) { return Math.max(min, Math.min(max, Math.round(n))); }
        function item(card) {
            return {x:Number(card.dataset.x),y:Number(card.dataset.y),width:Number(card.dataset.width)||310,
                height:Number(card.dataset.height)||320,color:card.dataset.color,frame:card.dataset.frame || ''};
        }
        function items(list) { var out={}; list.forEach(function(c){out[c.dataset.id]=item(c);}); return out; }
        function save(patch) {
            // Snapshot every command; the queue binds each command to the latest acknowledged revision.
            var snapshot = JSON.stringify(patch); pending++; status.textContent = 'Сохраняем доску…';
            queue = queue.then(async function () {
                if (failed) { pending--; return; }
                try {
                    var response = await fetch(root.dataset.url, {method:'POST',credentials:'same-origin',
                        body:new URLSearchParams({action:'board',patch:snapshot,revision:String(revision),sesskey:root.dataset.sesskey}),
                        headers:{'Accept':'application/json'}});
                    var result = await response.json();
                    if (!response.ok || !result.ok || !Number.isInteger(result.revision)) { throw new Error(result.error || 'Не удалось сохранить доску.'); }
                    revision=result.revision; pending--; status.textContent=pending ? 'Сохраняем доску…' : 'Доска сохранена';
                } catch(error) {
                    failed=true; pending--; status.textContent=error.message+' Обновите страницу перед следующими изменениями.';
                    root.querySelectorAll('[data-board-tools] button, [data-board-tools] select, [data-select], [data-port], [data-resize]').forEach(function(el){el.disabled=true;});
                }
            });
        }
        window.addEventListener('beforeunload',function(e){if(pending || failed){e.preventDefault();e.returnValue='';}});
        function size() {
            var width=Math.max(800,root.querySelector('.un-viewport').clientWidth / scale),height=600;
            cards.forEach(function(c){width=Math.max(width,Number(c.dataset.x)+Number(c.dataset.width)+50);height=Math.max(height,Number(c.dataset.y)+Number(c.dataset.height)+50);});
            Object.values(frames).forEach(function(f){width=Math.max(width,f.x+f.width+50);height=Math.max(height,f.y+f.height+50);});
            canvas.style.width=width+'px';canvas.style.height=height+'px';canvas.style.transform='scale('+scale+')';
            sizer.style.width=width*scale+'px';sizer.style.height=height*scale+'px';
        }
        function position(card, value) {
            Object.keys(value).forEach(function(k){card.dataset[k]=String(value[k]);});
            card.style.left=card.dataset.x+'px';card.style.top=card.dataset.y+'px';
            card.style.width=card.dataset.width+'px';card.style.height=card.dataset.height+'px';
        }
        function renderLinks() {
            lineLayer.replaceChildren();
            links.forEach(function(link){
                var from=cards.find(function(c){return Number(c.dataset.id)===link.from;}),to=cards.find(function(c){return Number(c.dataset.id)===link.to;});
                if(!from || !to) return;
                var x=Number(from.dataset.x)+Number(from.dataset.width),y=Number(from.dataset.y)+Number(from.dataset.height)/2;
                var tx=Number(to.dataset.x),ty=Number(to.dataset.y)+Number(to.dataset.height)/2;
                var line=document.createElementNS('http://www.w3.org/2000/svg','path');
                line.setAttribute('d','M '+x+' '+y+' C '+(x+80)+' '+y+' '+(tx-80)+' '+ty+' '+tx+' '+ty);
                line.setAttribute('tabindex','0');line.setAttribute('role','button');line.setAttribute('aria-label','Удалить связь '+from.querySelector('[data-drag]').textContent.trim()+' → '+to.querySelector('[data-drag]').textContent.trim());
                var title=document.createElementNS(line.namespaceURI,'title');title.textContent='Нажмите, чтобы удалить связь';line.append(title);
                function remove(){if(failed)return;links=links.filter(function(v){return v!==link;});renderLinks();save({links:links});}
                line.addEventListener('click',remove);line.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key==='Delete'){e.preventDefault();remove();}});lineLayer.append(line);
            });
        }
        function paint(){size();renderLinks();}
        function gesture(handle, read, apply, finish, limits) {
            var drag=null;
            handle.addEventListener('pointerdown',function(e){if(e.button!==0 || failed)return;e.preventDefault();drag={id:e.pointerId,px:e.clientX,py:e.clientY,base:read()};handle.setPointerCapture(e.pointerId);});
            handle.addEventListener('pointermove',function(e){if(!drag || e.pointerId!==drag.id)return;apply(drag.base,(e.clientX-drag.px)/scale,(e.clientY-drag.py)/scale,limits);paint();});
            function end(e){if(!drag || e.pointerId!==drag.id)return;if(e.type==='pointercancel')apply(drag.base,0,0,limits);else finish();drag=null;paint();}
            handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',end);
            handle.addEventListener('keydown',function(e){var d={ArrowLeft:[-20,0],ArrowRight:[20,0],ArrowUp:[0,-20],ArrowDown:[0,20]}[e.key];if(!d || failed)return;e.preventDefault();apply(read(),d[0],d[1],limits);paint();finish();});
        }
        function renderFrames() {
            frameLayer.replaceChildren();
            Object.entries(frames).forEach(function(pair){
                var id=pair[0],f=pair[1],box=document.createElement('section');box.className='un-frame';
                function layout(){box.style.left=f.x+'px';box.style.top=f.y+'px';box.style.width=f.width+'px';box.style.height=f.height+'px';}layout();
                var head=document.createElement('div');head.className='un-frame-head';
                var handle=document.createElement('button');handle.type='button';handle.className='un-frame-handle';handle.textContent='⠿';handle.setAttribute('aria-label','Переместить фрейм '+f.title);
                var title=document.createElement('input');title.value=f.title;title.maxLength=100;title.setAttribute('aria-label','Название фрейма');
                title.addEventListener('change',function(){if(failed)return;f.title=title.value;refreshPickers();save({frames:frames});});
                var remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label','Разгруппировать фрейм '+f.title);
                remove.addEventListener('click',function(){if(failed)return;delete frames[id];cards.filter(function(c){return c.dataset.frame===id;}).forEach(function(c){c.dataset.frame='';});renderFrames();refreshPickers();paint();save({frames:frames,items:items(cards)});});
                head.append(handle,title,remove);box.append(head);
                var resize=document.createElement('button');resize.type='button';resize.className='un-resize';resize.textContent='↘';resize.setAttribute('aria-label','Размер фрейма '+f.title);box.append(resize);
                frameLayer.append(box);
                gesture(handle,function(){return {x:f.x,y:f.y,members:cards.filter(function(c){return c.dataset.frame===id;}).map(function(c){return {card:c,x:Number(c.dataset.x),y:Number(c.dataset.y)};})};},function(b,dx,dy){
                    var xs=[b.x].concat(b.members.map(function(m){return m.x;})),ys=[b.y].concat(b.members.map(function(m){return m.y;}));
                    dx=clamp(dx,-Math.min.apply(null,xs),5000-Math.max.apply(null,xs));dy=clamp(dy,-Math.min.apply(null,ys),5000-Math.max.apply(null,ys));f.x=b.x+dx;f.y=b.y+dy;layout();
                    b.members.forEach(function(m){position(m.card,{x:m.x+dx,y:m.y+dy});});
                },function(){save({frames:frames,items:items(cards.filter(function(c){return c.dataset.frame===id;}))});});
                gesture(resize,function(){return {width:f.width,height:f.height};},function(b,dx,dy){f.width=clamp(b.width+dx,280,5000);f.height=clamp(b.height+dy,180,5000);layout();},function(){save({frames:frames});});
            });
        }
        function refreshPickers(){cards.forEach(function(card){var picker=card.querySelector('[data-frame-picker]');picker.replaceChildren(new Option('Без фрейма',''));Object.entries(frames).forEach(function(p){picker.add(new Option(p[1].title,p[0]));});picker.value=card.dataset.frame;});}
        function connect(from,to){linking=null;cards.forEach(function(c){c.classList.remove('is-link-source');});if(failed||from===to)return;
            if(!links.some(function(l){return l.from===from && l.to===to;})){links.push({from:from,to:to});renderLinks();save({links:links});}}
        cards.forEach(function(card){
            position(card,item(card));
            card.querySelector('[data-select]').addEventListener('change',function(e){card.classList.toggle('is-selected',e.target.checked);});
            var menu=card.querySelector('[data-menu]'),toggle=card.querySelector('[data-menu-toggle]');
            toggle.setAttribute('aria-expanded',String(!menu.hidden));toggle.addEventListener('click',function(){menu.hidden=!menu.hidden;toggle.setAttribute('aria-expanded',String(!menu.hidden));});
            var picker=card.querySelector('[data-color-picker]');picker.value=card.dataset.color;
            picker.addEventListener('change',function(){if(failed)return;card.dataset.color=picker.value;save({items:items([card])});});
            card.querySelector('[data-frame-picker]').addEventListener('change',function(e){if(failed)return;card.dataset.frame=e.target.value;save({items:items([card])});});
            gesture(card.querySelector('[data-drag]'),function(){return item(card);},function(b,dx,dy){position(card,{x:clamp(b.x+dx,0,5000),y:clamp(b.y+dy,0,5000)});},function(){save({items:items([card])});});
            gesture(card.querySelector('[data-resize]'),function(){return item(card);},function(b,dx,dy){position(card,{width:clamp(b.width+dx,240,1200),height:clamp(b.height+dy,180,1200)});},function(){save({items:items([card])});});
            var port=card.querySelector('[data-port]'),source=Number(card.dataset.id),moved=false;
            port.addEventListener('pointerdown',function(e){if(e.button!==0||failed)return;moved=false;port.setPointerCapture(e.pointerId);});
            port.addEventListener('pointermove',function(){moved=true;});
            port.addEventListener('pointerup',function(e){if(!moved||failed)return;var hit=document.elementFromPoint(e.clientX,e.clientY),target=hit&&hit.closest('.un-card');if(target)connect(source,Number(target.dataset.id));});
            port.addEventListener('click',function(){if(failed||moved)return;if(linking)connect(linking,source);else{linking=source;card.classList.add('is-link-source');status.textContent='Выберите точку на второй карточке';}});
        });
        root.querySelector('[data-group]').addEventListener('click',function(){if(failed)return;var selected=cards.filter(function(c){return c.querySelector('[data-select]').checked;});
            if(!selected.length){status.textContent='Выделите заметки галочками в заголовках.';return;}
            var x=Math.max(0,Math.min.apply(null,selected.map(function(c){return Number(c.dataset.x);}))-20),y=Math.max(0,Math.min.apply(null,selected.map(function(c){return Number(c.dataset.y);}))-50);
            var right=Math.max.apply(null,selected.map(function(c){return Number(c.dataset.x)+Number(c.dataset.width);})),bottom=Math.max.apply(null,selected.map(function(c){return Number(c.dataset.y)+Number(c.dataset.height);}));
            var id='f'+Date.now().toString(36)+Math.random().toString(36).slice(2,8);frames[id]={title:'Новый фрейм',x:x,y:y,width:clamp(right-x+20,280,5000),height:clamp(bottom-y+20,180,5000)};
            selected.forEach(function(c){c.dataset.frame=id;});renderFrames();refreshPickers();paint();save({frames:frames,items:items(selected)});
        });
        root.querySelector('[data-clear-selection]').addEventListener('click',function(){cards.forEach(function(c){c.querySelector('[data-select]').checked=false;c.classList.remove('is-selected','is-link-source');});linking=null;});
        background.value=board.background||'auto';root.dataset.background=background.value;
        background.addEventListener('change',function(){if(failed)return;root.dataset.background=background.value;save({background:background.value});});
        root.querySelectorAll('[data-zoom]').forEach(function(button){button.addEventListener('click',function(){scale=button.dataset.zoom==='reset'?1:clamp((scale+(button.dataset.zoom==='in'?.1:-.1))*10,5,15)/10;root.querySelector('[data-zoom-label]').textContent=Math.round(scale*100)+'%';paint();});});
        renderFrames();refreshPickers();paint();window.addEventListener('resize',size);
        if(window.location.hash){var target=document.getElementById(window.location.hash.slice(1));if(target&&canvas.contains(target)&&target.scrollIntoView){target.scrollIntoView({block:'nearest',inline:'nearest'});}}
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
}());
