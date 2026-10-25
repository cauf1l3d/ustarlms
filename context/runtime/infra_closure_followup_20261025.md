# Следующий read-only срез25Oct после local_batch_applied

Owner success17:49MSK принят; --apply не повторять. [Acceptance](infra_one_pass_owner_20261025.json#accepted_apply).
Далее определить service owner/purpose wildcard2019 и current task retry/disabled
metadata. Это не новый full survey и не повтор прежнего cron recovery/upgrade stand.
Свежий receipt и counts2failed/19overdue сами по себе не доказывают новую аварию:
20/21Oct уже приняты residual2 и18–19overdue. Ни один task не запускать/сбрасывать.

Один блок только чтения, без config/Env/credentials/raw task output:

```bash
sudo -v
sudo -n timeout --kill-after=2s 10s ss -H -lntp 'sport = :2019'
sudo -n timeout --kill-after=2s 10s systemctl show caddy.service \
  -p Id -p MainPID -p User -p Group -p FragmentPath -p ControlGroup --no-pager
sudo -n timeout --kill-after=2s 10s docker ps \
  --format 'table {{.Names}}\t{{.Ports}}'

sudo -n timeout --kill-after=3s 25s docker exec -i ustar_postgres sh -c \
  'exec psql -X -w -q -P pager=off -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<'SQL'
BEGIN READ ONLY;
SET LOCAL statement_timeout = '8s';
SET LOCAL lock_timeout = '2s';
SET LOCAL search_path = pg_catalog;
SET LOCAL TIME ZONE 'Europe/Moscow';
SET LOCAL ROLE ustar_app;
SELECT classname, disabled, faildelay,
       CASE WHEN lastruntime > 0 THEN to_timestamp(lastruntime) END AS last_run_msk,
       CASE WHEN nextruntime > 0 THEN to_timestamp(nextruntime) END AS next_run_msk
FROM public.mdl_task_scheduled
WHERE faildelay > 0
ORDER BY disabled, classname
LIMIT 20;
ROLLBACK;
SQL
```

Вернуть вывод/ошибки. Никаких admin HTTP API calls на неизвестный2019 до определения
владельца. Сам wildcard socket не доказывает доступность из Интернета, название caddy
ещё не установлено для обоих listeners. Не печатать полный ExecStart/процессные args,
Caddy JSON/Caddyfile или Docker Env. При дальнейшей правке сохранить qualified
backup/recovery controls и original unit/config before images, без слепого firewall,
SSH/password change/containers recreate. Shared host также обслуживает mail/DNS/
ISPConfig/AutoReport. Docker lifecycle отдельно согласует старые frozen IDs.

External clean-host restore/offsite/key custody/UPS/key-login/independent observer/
SMTP recipient/RPO/RTO/SLO требуют отдельных фактов; прошлые owner stands не повторять.
