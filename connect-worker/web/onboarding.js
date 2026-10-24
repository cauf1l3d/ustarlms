/* No private tokens or forms in URL/storage; no automatic mutation retries. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const form = $('registration-form');
  let csrf = '', pending = false, linkSubject = '', actorScope = false, actorBlocked = false, expiryTimer;
  function clearProfile() {
    clearTimeout(expiryTimer); $('actor-profile').hidden = true;
    for (const id of ['fullname','username','position','department','state']) $('profile-'+id).textContent = '';
  }
  const names = {firstname:'имя', lastname:'фамилию', username:'логин', email:'email', password:'пароль', departmentid:'подразделение'};
  const expired = new Set(['session_expired','invalid_entry','revoked_entry','restart_required','expired_launch','launch_consumed','replayed_proof','launch_mismatch','invalid_actor']);
  function showError(reason, field) {
    clearProfile(); $('activate').hidden = true;
    if (actorScope) {
      $('status-badge').textContent='Не подтверждено'; $('status-title').textContent='Профиль недоступен';
      $('status-detail').textContent='Текущие данные скрыты. Обновите проверку или начните новый защищённый вход.';
      $('status-time').textContent='';
    }
    if (['native_auth_required','actor_unavailable','invalid_actor','activation_outcome_unknown'].includes(reason)) actorBlocked = true;
    let text = 'Не удалось выполнить запрос. Проверьте статус заявки перед повторной отправкой.';
    if (reason==='source_unavailable') text = 'Связь недоступна. Результат отправки может быть неизвестен. Проверьте статус; автоматической повторной отправки нет.';
    if (expired.has(reason)) text = 'Откройте приложение заново из бота: защищённая сессия истекла или отозвана.';
    if (reason==='invalid_launch' || reason==='launch_required') text = 'Нужен новый защищённый запуск из личного диалога с ботом Академии.';
    if (reason==='rate_limited' || reason==='busy') text = 'Слишком много запросов или операция ещё выполняется. Подождите и проверьте статус.';
    if (reason==='idempotency_conflict') text = 'Данные отличаются от уже отправленной заявки. Проверьте статус, не создавайте новый аккаунт.';
    if (reason==='already_registered' || reason==='receipt_expired') text = 'Учётная запись уже связана. Проверьте её статус, повторная регистрация не нужна.';
    if (reason==='expired_challenge') text = 'Код привязки истёк. Создайте новую персональную ссылку в личном кабинете Академии → Мессенджеры.';
    if (reason==='invalid_challenge' || reason==='invalid_link_code' || reason==='provider_mismatch') text = 'Ссылка привязки недействительна для этого бота. Вернитесь в Академию и создайте новую ссылку.';
    if (reason==='claimed_challenge') text = 'Подтверждение по этой ссылке уже отправлено. Вернитесь в тот же браузер Академии и проверьте Telegram ID. Повторная регистрация не нужна.';
    if (reason==='link_outcome_unknown') {
      text = 'Результат привязки неизвестен. Вернитесь в тот же браузер Академии и нажмите «Проверить подтверждение Telegram». Автоматического повтора нет; если подтверждение не пришло, создайте новую персональную ссылку.';
      $('session-actions').hidden = false; $('refresh').hidden = true;
    }
    if (reason==='native_auth_required') text = 'Используйте штатный вход Академии: политика аккаунта требует дополнительной проверки. Этот профиль не заменяет MFA, SSO или обязательную смену пароля.';
    if (reason==='actor_unavailable') text = 'Вход в профиль через мессенджер пока выключен. Используйте штатный вход Академии.';
    if (reason==='activation_outcome_unknown') text = 'Результат входа неизвестен. Закройте сессию и отправьте боту новый /start. Автоматического повтора нет.';
    if (reason==='validation_failed') {
      text = names[field] ? `Проверьте ${names[field]}. Значение не прошло проверку Академии; логин или email также могут быть заняты.` : 'Проверьте данные формы.';
      if (names[field]) {$(field).setAttribute('aria-invalid','true'); $(field).focus();}
    }
    $('error').textContent = text; $('error').hidden = false;
    if (expired.has(reason) || ['link_outcome_unknown','expired_challenge','invalid_challenge',
      'invalid_link_code','provider_mismatch','claimed_challenge','link_only_session'].includes(reason)) form.hidden = true;
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
    clearProfile(); $('activate').hidden = true;
    if (value.scope==='actor_read') {
      actorScope = true; form.hidden = true;
      $('status-badge').textContent = 'Аккаунт активен';
      $('status-title').textContent = 'Мой профиль Академии';
      $('status-detail').textContent = 'Открыт собственный профиль по подтверждённой связи с мессенджером.';
      for (const key of ['fullname','username','position','department']) $('profile-'+key).textContent = value.profile[key] || 'Не указано';
      $('profile-state').textContent = 'Активна';
      $('actor-profile').hidden = false;
      $('status-time').textContent = 'Проверено: '+new Date().toLocaleString('ru-RU')+'. Доступ при бездействии до '+new Date(value.idle_expires_at*1000).toLocaleTimeString('ru-RU')+'.';
      $('refresh').textContent = 'Обновить профиль';
      expiryTimer = setTimeout(()=>showError('session_expired'), Math.max(0,value.idle_expires_at*1000-Date.now()));
      return;
    }
    if (value.scope==='link_confirmation') {
      $('status-badge').textContent = 'Telegram подтверждён';
      $('status-title').textContent = 'Подтвердите связь в Академии';
      const subject = linkSubject ? `Ваш Telegram ID: ${linkSubject}. ` : '';
      $('status-detail').textContent = subject+'Связь ещё не завершена этим экраном. Вернитесь в тот же браузер Академии → Мессенджеры, нажмите «Проверить подтверждение Telegram», сверьте ID и подтвердите связь. Если уже подтвердили — отправьте боту новый /start.';
      $('status-time').textContent = 'Этот экран подтверждает отправку Telegram. Окончательный результат показан в Академии.';
      $('refresh').textContent = 'Показать следующие шаги';
      form.hidden = true;
      return;
    }
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
    $('activate').hidden = state!=='linked_active' || actorBlocked;
    $('refresh').textContent = 'Проверить статус';
  }
  async function refresh() {
    clearProfile(); $('activate').hidden = true;
    if (actorScope) $('status-badge').textContent='Проверка';
    const value = await api('status', {});
    statusView(value);
    if (value.scope!=='link_confirmation' && value.account_state==='unlinked') {
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
  $('activate').addEventListener('click',()=>action(async()=>{
    clearProfile(); $('activate').hidden=true;
    let result;
    try { result=await api('activate',{}); }
    catch(error) { if(error.reason==='source_unavailable') error.reason='activation_outcome_unknown'; throw error; }
    csrf=result.csrf; actorScope=true;
    await refresh();
  }));
  $('refresh').addEventListener('click',()=>action(refresh));
  $('logout').addEventListener('click',()=>action(async()=>{
    try { await api('logout',{}); } catch(error) { if(!expired.has(error.reason)) throw error; }
    clearProfile(); actorScope=false; actorBlocked=false; $('activate').hidden=true; csrf=''; linkSubject=''; form.reset(); form.hidden=true; $('session-actions').hidden=true;
    $('status-title').textContent='Сессия закрыта'; $('status-detail').textContent='Для нового входа откройте приложение из бота.'; $('status-time').textContent='';
  }));
  document.addEventListener('visibilitychange',()=>{
    if (document.hidden) clearProfile();
    else if (actorScope && !pending) action(refresh);
  });
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
      if (result.scope==='link_confirmation' && /^[1-9][0-9]{0,15}$/.test(result.telegram_id||'')) linkSubject=result.telegram_id;
    }
    $('session-actions').hidden=false;
    await refresh();
  });
})();
