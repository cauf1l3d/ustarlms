/* No private tokens or forms in URL/storage; no automatic mutation retries. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const form = $('registration-form');
  let csrf = '', pending = false;
  const names = {firstname:'имя', lastname:'фамилию', username:'логин', email:'email', password:'пароль', departmentid:'подразделение'};
  const expired = new Set(['session_expired','invalid_entry','revoked_entry','restart_required','expired_launch','launch_consumed','replayed_proof','launch_mismatch']);
  function showError(reason, field) {
    let text = 'Не удалось выполнить запрос. Проверьте статус заявки перед повторной отправкой.';
    if (reason==='source_unavailable') text = 'Связь недоступна. Результат отправки может быть неизвестен. Проверьте статус; автоматической повторной отправки нет.';
    if (expired.has(reason)) text = 'Откройте приложение заново из бота: защищённая сессия истекла или отозвана.';
    if (reason==='invalid_launch' || reason==='launch_required') text = 'Нужен новый защищённый запуск из личного диалога с ботом Академии.';
    if (reason==='rate_limited' || reason==='busy') text = 'Слишком много запросов или операция ещё выполняется. Подождите и проверьте статус.';
    if (reason==='idempotency_conflict') text = 'Данные отличаются от уже отправленной заявки. Проверьте статус, не создавайте новый аккаунт.';
    if (reason==='already_registered' || reason==='receipt_expired') text = 'Учётная запись уже связана. Проверьте её статус, повторная регистрация не нужна.';
    if (reason==='validation_failed') {
      text = names[field] ? `Проверьте ${names[field]}. Значение не прошло проверку Академии; логин или email также могут быть заняты.` : 'Проверьте данные формы.';
      if (names[field]) {$(field).setAttribute('aria-invalid','true'); $(field).focus();}
    }
    $('error').textContent = text; $('error').hidden = false;
    if (expired.has(reason)) form.hidden = true;
  }
  async function api(path, data) {
    const abort = new AbortController(), timer = setTimeout(()=>abort.abort(),12000);
    try {
      const response = await fetch('/api/'+path, {method:data===undefined?'GET':'POST',
        credentials:'same-origin', cache:'no-store', redirect:'error', signal:abort.signal,
        headers:{'X-Ustar-Connect':'1','X-CSRF-Token':csrf,'Content-Type':'application/json'},
        body:data===undefined?undefined:JSON.stringify(data)});
      const value = await response.json();
      if (!response.ok || value.ok!==true) throw {reason:value.reason||'source_unavailable', field:value.field};
      return value;
    } catch (error) {throw {reason:typeof error.reason==='string'?error.reason:'source_unavailable',field:error.field};}
    finally {clearTimeout(timer);}
  }
  function statusView(value) {
    const state = value.registration?.status==='rejected'?'rejected':value.account_state;
    const display = {
      unlinked:['Регистрация','Заполните заявку','Укажите данные своей учётной записи. Доступ к обучению откроется только после решения HRD.'],
      pending_approval:['Ожидает HRD','Заявка на рассмотрении','Аккаунт создан. HRD рассмотрит заявку в Академии. Повторная регистрация не нужна.'],
      linked_active:['Одобрено','Учётная запись одобрена','Ваш аккаунт активен. Полный учебный вход через этот Mini App ещё не подключён.'],
      rejected:['Решение HRD','Заявка отклонена','Обратитесь к HRD для уточнения. Эта форма не удаляет аккаунт и не отправляет новую заявку.']
    }[state];
    if (!display) throw {reason:'source_unavailable'};
    [$('status-badge').textContent,$('status-title').textContent,$('status-detail').textContent] = display;
    const observed = new Date().toLocaleString('ru-RU');
    $('status-time').textContent = 'Проверено: '+observed;
    form.hidden = state!=='unlinked';
  }
  async function refresh() {
    const value = await api('status', {});
    statusView(value);
    if (value.account_state==='unlinked') {
      const result = await api('options', {}), select = $('departmentid'), previous = select.value;
      select.replaceChildren(new Option('Выберите подразделение',''));
      for (const item of result.departments) select.add(new Option(item.name,item.id));
      select.value = previous;
    }
  }
  async function action(work) {
    if (pending) return;
    pending = true; $('error').hidden = true;
    for (const button of document.querySelectorAll('button:not(#theme)')) button.disabled = true;
    try {await work();} catch(error) {showError(error.reason,error.field);}
    finally {pending = false; for(const button of document.querySelectorAll('button')) button.disabled = false;}
  }
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (pending) return;
    for (const name of Object.keys(names)) $(name).removeAttribute('aria-invalid');
    const data = Object.fromEntries(new FormData(form));
    $('password').value = '';
    action(async()=>{
      const result = await api('register',data);
      statusView({account_state:result.registration?.status==='approved'?'linked_active':'pending_approval',registration:result.registration});
    });
  });
  $('refresh').addEventListener('click',()=>action(refresh));
  $('logout').addEventListener('click',()=>action(async()=>{
    await api('logout',{}); csrf=''; form.reset(); form.hidden=true; $('session-actions').hidden=true;
    $('status-title').textContent='Сессия закрыта'; $('status-detail').textContent='Для нового входа откройте приложение из бота.'; $('status-time').textContent='';
  }));
  function theme(dark) {
    document.documentElement.dataset.theme = dark?'dark':'light';
    $('theme').textContent = dark?'Светлая тема':'Тёмная тема';
  }
  const preference = matchMedia('(prefers-color-scheme: dark)'); theme(preference.matches);
  $('theme').addEventListener('click',()=>theme(document.documentElement.dataset.theme!=='dark'));
  action(async()=>{
    const bootstrap = await api('bootstrap'); csrf = bootstrap.csrf;
    if (!bootstrap.authenticated) {
      const tg = window.Telegram?.WebApp;
      if (!tg?.initData) {
        $('status-title').textContent='Откройте приложение из бота';
        $('status-detail').textContent='Обычная ссылка не подтверждает ваш Telegram-аккаунт. Нужен персональный защищённый запуск; пароль в чат не отправляйте.';
        return;
      }
      tg.ready(); tg.expand();
      const result = await api('launch',{init_data:tg.initData}); csrf = result.csrf;
    }
    $('session-actions').hidden=false;
    await refresh();
  });
})();
