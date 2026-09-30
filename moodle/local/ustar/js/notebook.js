(function() {
'use strict';
function init() {
 var root=document.querySelector('[data-notebook]');
 if(!root || !window.fetch || !window.PointerEvent) return;
 var viewport=root.querySelector('.un-viewport'); if(!viewport) return;
 var canvas=root.querySelector('.un-canvas'), space=root.querySelector('.un-space');
 var cards=Array.from(root.querySelectorAll('.un-card')), scale=1, status=root.querySelector('[data-status]');
 function size() {
  var width=Math.max(1000,viewport.clientWidth),height=600;
  cards.forEach(function(c){width=Math.max(width,Number(c.dataset.x)+350);height=Math.max(height,Number(c.dataset.y)+c.offsetHeight+30);});
  canvas.style.width=width+'px';canvas.style.height=height+'px';canvas.style.transform='scale('+scale+')';
  space.style.width=width*scale+'px';space.style.height=height*scale+'px';
  root.querySelector('[data-scale]').textContent=Math.round(scale*100)+'%';
 }
 function position(c,x,y) {
  c.dataset.x=Math.round(Math.max(0,Math.min(10000,x)));c.dataset.y=Math.round(Math.max(0,Math.min(10000,y)));
  c.style.left=c.dataset.x+'px';c.style.top=c.dataset.y+'px';
 }
 function save(c) {
  var data=new FormData();data.set('sesskey',root.dataset.sesskey);data.set('action','layout');
  data.set('noteid',c.dataset.id);data.set('x',c.dataset.x);data.set('y',c.dataset.y);
  c.saveQueue=(c.saveQueue||Promise.resolve()).catch(function(){}).then(function(){
   status.textContent='Сохраняем расположение…';
   return fetch(root.dataset.url,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){if(!r.ok||r.redirected)throw new Error();return r.json();})
    .then(function(r){if(!r.ok)throw new Error();status.textContent='Расположение сохранено.';})
    .catch(function(){status.textContent='Не удалось сохранить расположение. Переместите карточку ещё раз.';});
  });
 }
 cards.forEach(function(c) {
  position(c,Number(c.dataset.x),Number(c.dataset.y));
  var handle=c.querySelector('.un-handle'),drag=null,timer;handle.hidden=false;
  handle.addEventListener('pointerdown',function(e){
   if(!root.classList.contains('is-board')||e.button!==0)return;
   drag={id:e.pointerId,px:e.clientX,py:e.clientY,x:Number(c.dataset.x),y:Number(c.dataset.y)};
   handle.setPointerCapture(e.pointerId);c.classList.add('is-moving');
  });
  handle.addEventListener('pointermove',function(e){if(drag&&drag.id===e.pointerId)position(c,drag.x+(e.clientX-drag.px)/scale,drag.y+(e.clientY-drag.py)/scale);});
  function end(e){if(!drag||drag.id!==e.pointerId)return;if(e.type==='pointercancel')position(c,drag.x,drag.y);else save(c);drag=null;c.classList.remove('is-moving');size();}
  handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',end);
  handle.addEventListener('keydown',function(e){
   if(!root.classList.contains('is-board'))return;
   var d={ArrowLeft:[-20,0],ArrowRight:[20,0],ArrowUp:[0,-20],ArrowDown:[0,20]}[e.key];if(!d)return;
   e.preventDefault();position(c,Number(c.dataset.x)+d[0],Number(c.dataset.y)+d[1]);size();
   clearTimeout(timer);timer=setTimeout(function(){save(c);},250);
  });
 });
 root.classList.add('is-board');root.querySelector('.un-board-tools').hidden=false;root.querySelector('.un-hint').hidden=false;
 root.querySelector('[data-mode]').addEventListener('click',function(e){
  var board=root.classList.toggle('is-board');
  e.currentTarget.textContent=board?'Список':'Доска';e.currentTarget.setAttribute('aria-pressed',board?'false':'true');
  cards.forEach(function(c){c.querySelector('.un-handle').hidden=!board;});
  canvas.style.width='';canvas.style.height='';canvas.style.transform='';space.style.width='';space.style.height='';
  if(board)size();
 });
 root.querySelectorAll('[data-zoom]').forEach(function(b){b.addEventListener('click',function(){if(root.classList.contains('is-board')){scale=Math.max(.5,Math.min(1.5,scale+(b.dataset.zoom==='in'?.1:-.1)));size();}});});
 root.querySelector('[data-origin]').addEventListener('click',function(){viewport.scrollTo(0,0);});
 window.addEventListener('resize',function(){if(root.classList.contains('is-board'))size();});
 size();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
