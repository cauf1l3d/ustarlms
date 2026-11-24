'use strict';

const assert = require('node:assert/strict');
const {chromium} = require('playwright');

const base = 'http://127.0.0.1:8087';
const position = '/local/ustar/positions.php?positionid=retail_seller';

async function login(browser, username, viewport) {
    const context = await browser.newContext({viewport});
    const page = await context.newPage();
    await page.goto(base + '/login/index.php');
    await page.locator('#username').fill(username);
    await page.locator('.toggle-sensitive-wrapper #password').fill('Fixture-Only-Password1!');
    const [response] = await Promise.all([
        page.waitForResponse(reply => reply.url() === base + '/login/index.php' && reply.request().method() === 'POST'),
        page.locator('#loginbtn').click(),
    ]);
    assert.match(await response.headerValue('location') || '', /[?&]testsession=/);
    await page.waitForURL(url => !url.pathname.endsWith('/login/index.php'));
    return page;
}

async function main() {
    const browser = await chromium.launch({headless: true, executablePath: process.env.USTAR_TEST_CHROMIUM});
    try {
        const admin = await login(browser, 'admin', {width: 390, height: 844});
        await admin.goto(base + position + '&tab=learning');
        await admin.getByRole('heading', {name: 'Общий маршрут должности'}).waitFor();
        await admin.getByRole('heading', {name: 'Как подтверждаются навыки'}).waitFor();
        await admin.getByRole('heading', {name: 'Обязательные документы'}).waitFor();
        await admin.getByRole('link', {name: 'Настроить дополнительный маршрут для отдельных сотрудников'}).click();
        await admin.getByRole('heading', {name: 'Персональные модели', exact: true}).waitFor();
        await admin.getByLabel('Название модели').fill('Independent fixture');
        await admin.getByRole('button', {name: 'Сохранить модель'}).click();
        await admin.getByRole('heading', {name: 'Дополнительный маршрут'}).waitFor();
        const first = new URL(admin.url()).searchParams.get('profileid');
        assert.match(first, /^\d+$/);
        await admin.getByRole('link', {name: 'Новая модель'}).click();
        await admin.getByLabel('Название модели').fill('Advanced fixture');
        await admin.getByRole('button', {name: 'Сохранить модель'}).click();
        await admin.getByRole('link', {name: 'Advanced fixture', exact: true}).waitFor();
        const second = new URL(admin.url()).searchParams.get('profileid');
        assert.ok(second && second !== first);

        const member = admin.locator('.u-pos-member-row').filter({hasText: 'Work Member'});
        const colleague = admin.locator('.u-pos-member-row').filter({hasText: 'Work Colleague'});
        await member.locator('select').selectOption(first);
        await member.getByRole('button', {name: 'Применить'}).click();
        await colleague.locator('select').selectOption(second);
        await colleague.getByRole('button', {name: 'Применить'}).click();
        assert.equal(await member.locator('select').inputValue(), first);
        assert.equal(await colleague.locator('select').inputValue(), second);

        const employee = await login(browser, 'work_member', {width: 390, height: 844});
        const denied = await employee.goto(base + position + '&tab=personal');
        assert.ok(denied.status() >= 400 || await employee.locator('[data-rel=fatalerror]').count() > 0
            || await employee.getByRole('heading', {name: 'Персональные модели'}).count() === 0);
        await employee.goto(base + '/local/ustar/route_career.php');
        await employee.getByRole('heading', {name: 'Independent fixture', exact: true}).waitFor();
        assert.equal(await employee.getByRole('heading', {name: 'Advanced fixture', exact: true}).count(), 0);
        console.log('POSITION_MODELS_BROWSER=OK');
    } finally {
        await browser.close();
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
