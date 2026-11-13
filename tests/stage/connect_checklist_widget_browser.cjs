const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.USTAR_PLAYWRIGHT_PATH || '../mobile/node_modules/playwright');

const html = execFileSync('python3', ['-c',
    "import sys;sys.path.insert(0,'connect-worker');from ustar_connect.bot_widget import HTML;print(HTML)"],
    {encoding: 'utf8'});
const taskHtml = execFileSync('python3', ['-c',
    "import sys;sys.path.insert(0,'connect-worker');from ustar_connect.bot_widget import TASK_HTML;print(TASK_HTML)"],
    {encoding: 'utf8'});
const action = 'cb4_' + 'A'.repeat(43);
const items = [
    'Сотрудники отметились перед началом работы в торговом зале',
    'Внешний вид сотрудников соответствует внутренним правилам',
    'У каждого сотрудника есть бейдж и рабочая форма',
    'Чистота и порядок в зоне работы каждого сотрудника',
    '<img src="https://example.invalid/unsafe"> Проверить полки',
];

async function assertFits(page, control, width) {
    const [scrollWidth, clientWidth] = await page.locator('body').evaluate(el => [el.scrollWidth, el.clientWidth]);
    assert.ok(scrollWidth <= clientWidth, JSON.stringify({width, scrollWidth, clientWidth}));
    const bounds = await control.boundingBox();
    assert.ok(bounds && bounds.height >= 44 && bounds.y + bounds.height < 580,
        JSON.stringify({width, bounds}));
}

async function exercise(browser, width, dark, pages, fontSize = 15) {
    const page = await browser.newPage({viewport: {width, height: 800}});
    try {
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        const state = JSON.stringify({items, action});
        const shim = '<script>window.calls=[];window.handlers={};window.TelegramWidget={chatData:' +
            JSON.stringify(state) + ',onEvent:(name,fn)=>handlers[name]=fn,ready(){},request(value){calls.push(value);' +
            'return calls.length===1?Promise.reject(new Error("timeout")):' +
            'Promise.resolve({result:JSON.stringify({status:"saved",done:3,total:5})});}};</script>';
        const style = dark ? '<style>:root{--tg-theme-bg-color:#182535;--tg-theme-text-color:#eef4f8;' +
            '--tg-theme-secondary-bg-color:#283b50;--tg-theme-button-color:#367eb8;' +
            '--tg-theme-button-text-color:#fff;--tg-theme-hint-color:#bbc9d2;background:#182535}</style>' : '';
        await page.setContent(html.replace('<script>',
            style + '<style>:root{--tg-text-font-size:' + fontSize + 'px}</style>' + shim + '<script>'));
        const save = page.getByRole('button', {name: 'Отправить в Академию'});
        assert.equal(await page.locator('img').count(), 0);
        const next = page.getByRole('button', {name: 'Далее', exact: true});
        const previous = page.getByRole('button', {name: 'Назад', exact: true});
        assert.equal(await next.isVisible(), pages > 1);
        if (pages === 1) assert.ok(await save.isDisabled());
        await page.getByRole('group').first().getByRole('button', {name: 'Да', exact: true}).click();
        await page.getByRole('group').first().getByRole('button', {name: 'Нет', exact: true}).click();
        assert.equal(await page.getByRole('group').first().getByRole('button', {name: 'Нет'}).getAttribute('aria-pressed'), 'true');
        let indexOnPage = 0;
        for (let index = 1; index < items.length; index++) {
            indexOnPage++;
            if (indexOnPage === await page.getByRole('group').count()) {
                await assertFits(page, next, width);
                await next.click();
                indexOnPage = 0;
            }
            await page.getByRole('group').nth(indexOnPage)
                .getByRole('button', {name: index === 2 ? 'Нет' : 'Да', exact: true}).click();
        }
        assert.equal(await page.locator('.label').last().textContent(), items[4]);
        await assertFits(page, save, width);
        if (pages > 1) {
            await previous.click();
            assert.ok(await save.isHidden());
            await next.click();
            assert.ok(await save.isEnabled());
        }
        assert.equal(await page.locator('#progress').textContent(),
            'Отмечено 5 из 5' + (pages > 1 ? ' · ' + pages + '/' + pages : ''));
        await save.click();
        await page.getByText(/Подтверждение не получено/).waitFor();
        assert.ok(await save.isEnabled());
        await save.click();
        await page.getByText('Сохранено в Академии. Выполнено 3 из 5.').waitFor();
        assert.deepEqual(await page.evaluate(() => window.calls), [action + ':01011', action + ':01011']);
        await page.evaluate(() => handlers.chatDataChanged());
        assert.ok(await save.isHidden());
        assert.deepEqual(errors, []);
    } finally {
        await page.close();
    }
}

async function exerciseTask(browser, width, fontSize, dark) {
    const page = await browser.newPage({viewport: {width, height: 800}});
    try {
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        const details = {description: '<img src=x> ' + 'Ш'.repeat(148), due: '14.11.2026 17:00 (UTC+03:00)'};
        const initial = {kind: 'task', status: 'assigned', action, ...details};
        const after = {status: 'started', ...details};
        const shim = '<script>window.calls=[];window.handlers={};window.TelegramWidget={chatData:' +
            JSON.stringify(JSON.stringify(initial)) + ',onEvent:(name,fn)=>handlers[name]=fn,ready(){},request(value){' +
            'calls.push(value);return calls.length===1?Promise.reject(new Error("timeout")):' +
            'Promise.resolve({result:' + JSON.stringify(JSON.stringify(after)) + '});}};</script>';
        const theme = dark ? '--tg-theme-text-color:#eef4f8;--tg-theme-hint-color:#bbc9d2;background:#182535;' : '';
        const content = taskHtml.replace('<script>', '<style>:root{--tg-text-font-size:' + fontSize + 'px;' +
            theme + '}</style>' + shim + '<script>');
        await page.setContent(content);
        const start = page.getByRole('button', {name: 'Взять в работу'});
        await assertFits(page, start, width);
        assert.equal(await page.locator('img').count(), 0);
        assert.equal(await page.locator('#status').textContent(), 'Назначена');
        await start.click();
        await page.getByText(/Подтверждение не получено/).waitFor();
        assert.ok(await start.isEnabled());
        await start.click();
        await page.getByText('Сохранено в Академии.').waitFor();
        assert.equal(await page.locator('#status').textContent(), 'В работе');
        assert.equal(await page.locator('#due').textContent(), 'Срок: ' + details.due);
        assert.ok(await start.isHidden());
        assert.deepEqual(await page.evaluate(() => window.calls), [action + ':start', action + ':start']);
        await page.goto('about:blank');
        await page.setContent(content.replace(JSON.stringify(JSON.stringify(initial)), JSON.stringify(JSON.stringify(after))));
        await page.getByText('Сохранено в Академии.').waitFor();
        assert.ok(await start.isHidden());
        assert.deepEqual(errors, []);
    } finally {
        await page.close();
    }
}

(async () => {
    const browser = await chromium.launch({executablePath: process.env.USTAR_CHROMIUM_PATH});
    try {
        await exercise(browser, 200, false, 2);
        await exercise(browser, 320, true, 2);
        await exercise(browser, 200, true, 3, 22);
        console.log('CONNECT_CHECKLIST_WIDGET_BROWSER=PASS');
        await exerciseTask(browser, 200, 22, true);
        await exerciseTask(browser, 320, 15, false);
        console.log('CONNECT_TASK_WIDGET_BROWSER=PASS');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
