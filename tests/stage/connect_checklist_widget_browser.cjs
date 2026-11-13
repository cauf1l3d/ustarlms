const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.USTAR_PLAYWRIGHT_PATH || '../mobile/node_modules/playwright');

const html = execFileSync('python3', ['-c',
    "import sys;sys.path.insert(0,'connect-worker');from ustar_connect.bot_widget import HTML;print(HTML)"],
    {encoding: 'utf8'});
const action = 'cb4_' + 'A'.repeat(43);
const items = [
    'Сотрудники отметились перед началом работы в торговом зале',
    'Внешний вид сотрудников соответствует внутренним правилам',
    'У каждого сотрудника есть бейдж и рабочая форма',
    'Чистота и порядок в зоне работы каждого сотрудника',
    '<img src="https://example.invalid/unsafe"> Проверить полки',
];

async function exercise(browser, width, dark) {
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
        await page.setContent(html.replace('<script>', style + shim + '<script>'));
        const save = page.getByRole('button', {name: 'Отправить в Академию'});
        assert.ok(await save.isDisabled());
        assert.equal(await page.locator('img').count(), 0);
        assert.equal(await page.locator('.label').last().textContent(), items[4]);
        assert.ok(await page.locator('body').evaluate(el => el.scrollWidth <= el.clientWidth));
        assert.ok((await save.boundingBox()).y + 44 < 590);
        assert.ok((await save.boundingBox()).height >= 44);
        await page.getByRole('group').first().getByRole('button', {name: 'Да', exact: true}).click();
        await page.getByRole('group').first().getByRole('button', {name: 'Нет', exact: true}).click();
        assert.equal(await page.getByRole('group').first().getByRole('button', {name: 'Нет'}).getAttribute('aria-pressed'), 'true');
        for (let index = 1; index < items.length; index++) {
            await page.getByRole('group').nth(index).getByRole('button', {name: index === 2 ? 'Нет' : 'Да', exact: true}).click();
        }
        assert.equal(await page.locator('#progress').textContent(), 'Отмечено 5 из 5');
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

(async () => {
    const browser = await chromium.launch({executablePath: process.env.USTAR_CHROMIUM_PATH});
    try {
        await exercise(browser, 200, false);
        await exercise(browser, 320, true);
        console.log('CONNECT_CHECKLIST_WIDGET_BROWSER=PASS');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
