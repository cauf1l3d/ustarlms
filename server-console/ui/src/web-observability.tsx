import React from "react";

export type WebObservation = {
  observed_at: string; status: string; reason_code: string;
  window_seconds: number; coverage_seconds: number; requests: number | null;
  rps: number | null; p95_ms: number | null; errors_5xx: number | null; errors_4xx: number | null;
  events_status: string; events_reason_code: string;
  events: { at: string; source: string; level: string; code: string; count: number }[];
};
const number = (value: number | null | undefined, unit = "") =>
  typeof value === "number" && Number.isFinite(value) ? value.toLocaleString("ru-RU", {maximumFractionDigits: 2}) + unit : "—";
const reasons: Record<string,string> = {
  source_unavailable: "Источник недоступен. Для статистики запросов требуется однократно подключить минимальный журнал Apache по инструкции обновления.",
  source_empty: "В новом журнале ещё нет запросов. Статистика появится после реального обращения к Академии.",
  insufficient_coverage: "Неполное окно наблюдения. Ниже только полученные измерения; средний RPS рассчитан на полное окно 300 секунд.",
  source_invalid: "Источник не прошёл проверку формата или прав. Проверьте диагностику на сервере.",
  source_stale: "Журнал давно не получал записей. Нельзя отличить отсутствие обращений от остановки записи; текущая скорость не подтверждена.",
  source_limit: "Достигнут предел чтения. Полнота журнала не подтверждена.",
};
const date = (s:string) => new Date(s).toLocaleString("ru-RU",{timeZone:"Europe/Moscow"}) + " МСК";
function fresh(value:WebObservation|undefined, available:boolean) {
  const age=value ? (Date.now()-Date.parse(value.observed_at))/1000 : Infinity;
  return available && Number.isFinite(age) && age>=-5 && age<=45;
}
export function WebRequests({value,available}:{value?:WebObservation;available:boolean}) {
  const current=fresh(value,available);
  return <section className="panel" role="region" aria-label="Скорость запросов пользователей">
    <h2>Скорость запросов пользователей</h2>
    <p className="muted">Реальные запросы к Apache Академии за 5 минут. Собственный HEAD пульта исключён. p95 — время до завершения ответа веб-сервером, не время загрузки страницы на телефоне.</p>
    {!current ? <p>Измерения отсутствуют или устарели.</p> : <>
      {value!.status!=="ok" && <p className="notice">{reasons[value!.reason_code] || "Недостаточно измерений."}</p>}
      <dl><dt>Запросов</dt><dd>{number(value!.requests)}</dd>
        <dt>RPS · среднее за 300 с</dt><dd>{number(value!.rps," запросов/с")}</dd>
        <dt>p95</dt><dd>{number(value!.p95_ms," мс")}</dd>
        <dt>Ошибки HTTP 5xx / 4xx</dt><dd>{number(value!.errors_5xx)} / {number(value!.errors_4xx)}</dd>
        <dt>Покрытие окна</dt><dd>{number(value!.coverage_seconds," из 300 с")}</dd>
        <dt>Измерено</dt><dd>{date(value!.observed_at)}</dd></dl>
    </>}
  </section>;
}
export function ServerLogs({value,available}:{value?:WebObservation;available:boolean}) {
  const current=fresh(value,available);
  const codes:Record<string,string>={service_warning:"Предупреждение службы",service_error:"Ошибка службы",unit_failed:"Сбой запуска службы",disk_full:"Недостаточно места на диске",out_of_memory:"Недостаточно памяти",permission_denied:"Отказ в доступе"};
  return <section className="panel" role="region" aria-label="Журнал служб"><h2>Журнал служб</h2>
    <p className="muted">Последние 5 минут: Apache, резервное копирование и службы пульта. Только очищенные категории, время и число записей. Исходные сообщения, URL, IP и секреты остаются на сервере.</p>
    {!current ? <p>Измерения отсутствуют или устарели.</p> : value!.events_status!=="ok" ?
      <p>{reasons[value!.events_reason_code] || "Журнал недоступен или не прошёл проверку."}</p> :
      value!.events.length ? value!.events.map((row,i)=><div className="data-row" key={i}><span><strong>{codes[row.code] || "Событие службы"}</strong><small>{row.source} · {date(row.at)}</small></span><span>{number(row.count," записей")}</span></div>) :
      <p>За это окно предупреждений и ошибок выбранных служб не получено.</p>}
  </section>;
}
