"""Telegram's personal HTML card and the bounded widget query envelope."""
from dataclasses import dataclass
import json
import re

from .bridge import _token


HTML = """<!doctype html><html lang="ru"><meta charset="utf-8">
<style>
body{margin:8px 12px;color:var(--tg-theme-text-color,#172b3d);
font-family:system-ui;font-size:var(--tg-text-font-size,15px);line-height:1.35}
#progress{margin:0 0 8px;color:var(--tg-theme-hint-color,#4e6373)}
.row{margin:0 0 6px;padding:0 0 5px;border-bottom:1px solid var(--tg-theme-section-separator-color,#d4dfe7)}
.label{display:block;margin-bottom:3px;overflow-wrap:anywhere}
.options{display:flex;gap:6px}
button{min-height:44px;font-family:inherit;font-size:inherit;border:0;border-radius:8px;padding:5px 4px;overflow-wrap:anywhere}
.options button,#nav button{flex:1;min-width:0;background:var(--tg-theme-secondary-bg-color,#eef4f8);color:inherit}
.options .selected{background:var(--tg-theme-button-color,#266394);color:var(--tg-theme-button-text-color,#fff)}
#save{width:100%;background:var(--tg-theme-button-color,#266394);color:var(--tg-theme-button-text-color,#fff)}
#nav{display:flex;gap:6px;margin:3px 0 6px}#nav[hidden]{display:none}
button:disabled{opacity:.55}
#notice{margin:7px 0 0}
</style>
<body><p id="progress" aria-live="polite"></p><div id="items"></div>
<div id="nav" hidden><button id="previous" type="button">Назад</button><button id="next" type="button">Далее</button></div>
<button id="save" type="button">Отправить в Академию</button><p id="notice" role="status"></p>
<script>
const widget=TelegramWidget;
const items=document.querySelector('#items'),progress=document.querySelector('#progress');
const save=document.querySelector('#save'),notice=document.querySelector('#notice');
const nav=document.querySelector('#nav'),previous=document.querySelector('#previous'),next=document.querySelector('#next');
let model={},answers=[],buttons=[],busy=false,finished=false,page=0,pageSize=3;
function paint(pair,value){
  for(const button of pair){
    const selected=button.dataset.value===value;
    button.classList.toggle('selected',selected);button.setAttribute('aria-pressed',String(selected));
    button.textContent=(selected?'✓ ':'')+(button.dataset.value==='1'?'Да':'Нет');
  }
}
function showProgress(){
  const marked=answers.filter(value=>value!=='').length;
  const pages=Math.ceil(answers.length/pageSize),last=page+1>=pages;
  progress.textContent='Отмечено '+marked+' из '+answers.length+(pages>1?' · '+(page+1)+'/'+pages:'');
  nav.hidden=pages<2;previous.disabled=busy||page===0;next.disabled=busy||last;
  save.hidden=!last;
  save.disabled=busy||marked!==answers.length||!answers.length;
  for(const pair of buttons)for(const button of pair)button.disabled=busy;
}
function showResult(result){
  finished=true;busy=false;
  try{localStorage.removeItem('answers');}catch{}
  items.textContent='';save.hidden=true;nav.hidden=true;progress.textContent='';
  notice.textContent=result.status==='saved'
    ?'Сохранено в Академии. Выполнено '+result.done+' из '+result.total+'.'
    :'Чек-лист изменился или устарел. Откройте его заново в меню бота.';
}
function load(){
  try{model=JSON.parse(widget.chatData||'{}');}catch{model={};}
  if(model.status==='saved'){showResult(model);return;}
  if(finished)return;
  items.textContent='';buttons=[];save.hidden=false;notice.textContent='';
  if(!Array.isArray(model.items)||!model.action){
    save.hidden=true;notice.textContent='Откройте пошаговую форму под сообщением.';return;
  }
  let draft='';
  try{draft=localStorage.getItem('answers')||'';}catch{}
  answers=model.items.map((_,index)=>/[01]/.test(draft[index]||'')?draft[index]:'');
  page=0;render();
}
function render(){
  pageSize=parseFloat(getComputedStyle(document.body).fontSize)>18?2:3;
  page=Math.min(page,Math.ceil(answers.length/pageSize)-1);
  items.textContent='';buttons=[];
  const start=page*pageSize,end=Math.min(start+pageSize,answers.length);
  for(let index=start;index<end;index++){
    const label=model.items[index];
    const row=document.createElement('div'), title=document.createElement('span');
    const options=document.createElement('div'),pair=[];
    row.className='row';title.className='label';title.textContent=label;options.className='options';
    options.setAttribute('role','group');options.setAttribute('aria-label',label);
    for(const [value,text] of [['1','Да'],['0','Нет']]){
      const button=document.createElement('button');
      button.type='button';button.textContent=text;button.dataset.value=value;
      button.setAttribute('aria-label',text);
      button.addEventListener('click',()=>{
        answers[index]=value;
        paint(pair,value);
        try{localStorage.setItem('answers',answers.map(value=>value||'-').join(''));}catch{}
        showProgress();
      });
      pair.push(button);options.appendChild(button);
    }
    paint(pair,answers[index]);
    row.appendChild(title);row.appendChild(options);items.appendChild(row);buttons.push(pair);
  }
  showProgress();
}
previous.addEventListener('click',()=>{page--;render();});
next.addEventListener('click',()=>{page++;render();});
function retry(){
  busy=false;showProgress();
  notice.textContent='Подтверждение не получено. Нажмите «Отправить» ещё раз.';
}
save.addEventListener('click',()=>{
  if(save.disabled)return;
  busy=true;showProgress();notice.textContent='Сохраняем…';
  widget.request(model.action+':'+answers.join('')).then(answer=>{
    if(finished)return;
    const result=JSON.parse(answer.result||'{}');
    if(result.status==='saved'||result.status==='stale')showResult(result);
    else retry();
  }).catch(()=>{if(!finished)retry();});
});
widget.onEvent('chatDataChanged',load);load();widget.ready();
</script></body></html>"""


@dataclass(frozen=True, repr=False)
class WidgetQuery:
    update_id: int
    query_id: str
    subject: int
    message_id: int
    action: str
    answers: str


def _positive(value):
    return type(value) is int and 0 < value < 2**52


def parse_widget_query(update):
    if (not isinstance(update, dict) or set(update) != {'update_id', 'widget_query'}
            or not _positive(update['update_id'])):
        return None
    query = update['widget_query']
    # Shared, forwarded, inline and wallet interactions are outside this personal contract.
    if not isinstance(query, dict) or set(query) != {
            'id', 'from', 'chat', 'message_id', 'chat_instance', 'type', 'data'}:
        return None
    sender, chat, query_id = query['from'], query['chat'], query['id']
    if (query['type'] != {'type': 'data'} or not isinstance(sender, dict) or not isinstance(chat, dict)
            or not _positive(sender.get('id')) or sender.get('is_bot') is not False
            or chat.get('type') != 'private' or not _positive(chat.get('id')) or chat['id'] != sender['id']
            or not _positive(query['message_id']) or not isinstance(query_id, str)
            or not re.fullmatch(r'[!-~]{1,256}', query_id)
            or not isinstance(query['chat_instance'], str) or not re.fullmatch(r'-?[0-9]{1,20}', query['chat_instance'])
            or not isinstance(query['data'], str)):
        return None
    match = re.fullmatch(r'(cb4_[A-Za-z0-9_-]{43}):([01]{1,5})', query['data'])
    if match is None:
        return None
    try:
        action = _token(match[1], 'cb4')
    except ValueError:
        return None
    return WidgetQuery(update['update_id'], query_id, sender['id'], query['message_id'], action, match[2])


def encode_state(value):
    data = json.dumps(value, ensure_ascii=False, separators=(',', ':'), allow_nan=False)
    if len(data.encode()) > 1024:
        raise ValueError('widget_data_too_large')
    return data


def checklist_widget(view):
    if not isinstance(view, dict) or set(view) != {'action', 'items'}:
        raise ValueError('invalid_widget_view')
    _token(view['action'], 'cb4')
    items = view['items']
    if (not isinstance(items, list) or not 1 <= len(items) <= 5
            or any(not isinstance(item, str) or not item.strip() or len(item) > 65 for item in items)):
        raise ValueError('invalid_widget_view')
    return encode_state(view)


def widget_receipt(value):
    if value in ({'status': 'stale'}, {'status': 'retry'}):
        return value
    if (not isinstance(value, dict) or set(value) != {'status', 'done', 'total'}
            or value['status'] != 'saved' or type(value['done']) is not int or type(value['total']) is not int
            or not 1 <= value['total'] <= 5 or not 0 <= value['done'] <= value['total']):
        raise ValueError('invalid_widget_receipt')
    return value
