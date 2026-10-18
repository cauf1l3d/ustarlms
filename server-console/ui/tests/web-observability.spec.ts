import { test, expect } from '@playwright/test';
test('real request counters and classified logs are visible and stale values disappear',async({page})=>{
  let stale=false;
  await page.route('**/api/overview',route=>route.fulfill({json:{observed_at:new Date().toISOString(),received_at:new Date().toISOString(),age_seconds:stale?80:0,stale,host:{},backup:{},containers:[],lab:{},checks:[],domains:{},web_observability:{observed_at:new Date().toISOString(),status:'ok',reason_code:'measured',window_seconds:300,coverage_seconds:300,requests:777,rps:2.59,p95_ms:842,errors_5xx:3,errors_4xx:4,events_status:'ok',events_reason_code:'measured',events:[{at:new Date().toISOString(),source:'apache2.service',level:'error',code:'permission_denied',count:2}]}}}));
  await page.goto('/');await page.getByLabel('Логин',{exact:true}).fill('owner');await page.getByLabel('Пароль',{exact:true}).fill('browser-fixture-password');await page.getByRole('button',{name:'Войти',exact:true}).click();
  await page.getByRole('navigation').getByRole('button',{name:'Академия',exact:true}).click();
  const requests=page.getByRole('region',{name:'Скорость запросов пользователей'});
  await expect(requests).toContainText('842');await expect(requests).toContainText('777');await expect(requests).toContainText('Apache');
  await page.getByRole('navigation').getByRole('button',{name:'События',exact:true}).click();
  await expect(page.getByRole('region',{name:'Журнал служб'})).toContainText('Отказ в доступе');
  stale=true;await page.reload();await page.getByRole('navigation').getByRole('button',{name:'Академия',exact:true}).click();
  await expect(requests).not.toContainText('842');await expect(requests).toContainText('устарели');
});
