const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('../mobile/node_modules/playwright');

const base = 'http://127.0.0.1:8087';
const adminUrl = base + '/local/ustar/reward_shop_admin.php';
const shopUrl = base + '/local/ustar/reward_shop.php';

function adminCookies() {
    return fs.readFileSync('artifacts/shell-cookies.txt', 'utf8').split('\n')
        .filter(line => line && (!line.startsWith('#') || line.startsWith('#HttpOnly_'))).map(line => {
            const [domain,,path,secure,,name,value] = line.replace(/^#HttpOnly_/, '').split('\t');
            return {domain, path, secure: secure === 'TRUE', name, value, httpOnly: line.startsWith('#HttpOnly_')};
        });
}

async function visit(page, url) {
    assert.equal((await page.goto(url)).status(), 200);
}

async function submit(button, page) {
    await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), button.click()]);
}

(async () => {
    const browser = await chromium.launch();
    try {
        const admin = await browser.newContext({viewport: {width: 1366, height: 900}});
        await admin.addCookies(adminCookies());
        const manager = await admin.newPage();
        const employee = await browser.newContext({viewport: {width: 390, height: 844}});
        const learner = await employee.newPage();

        await visit(learner, base + '/login/index.php');
        await learner.locator('#username').fill('rewardfixture');
        await learner.locator('#password').fill('Fixture-Only-Password1!');
        await submit(learner.locator('#loginbtn'), learner);

        await visit(manager, adminUrl + '?section=offers');
        await manager.getByLabel('Название', {exact: true}).fill('Термокружка для теста');
        await manager.getByLabel('Описание и способ получения').fill('Выдача в офисе');
        await manager.getByLabel('Цена в USCOIN').fill('7');
        await manager.getByLabel('Остаток для новых заявок').fill('2');
        await manager.getByLabel('Публикация').selectOption('published');
        await submit(manager.getByRole('button', {name: 'Сохранить карточку'}), manager);

        await visit(learner, shopUrl);
        assert.equal(await learner.getByText('Термокружка для теста', {exact: true}).count(), 0);
        await learner.getByText('Сейчас витрина недоступна').waitFor();
        await visit(manager, adminUrl + '?section=settings');
        await manager.getByLabel('Открыть витрину выбранным отделам').check();
        await manager.getByLabel('Отдел проверки наград', {exact: true}).check();
        await submit(manager.getByRole('button', {name: 'Сохранить доступ'}), manager);

        await visit(learner, base + '/local/ustar/achievements.php');
        await learner.getByRole('link', {name: 'Магазин наград', exact: true}).click();
        await learner.getByText('Доступно 20 USCOIN').waitFor();
        await submit(learner.getByRole('button', {name: 'Подать заявку за 7 USCOIN'}), learner);
        await visit(learner, shopUrl);
        await learner.getByText('Доступно 13 USCOIN').waitFor();
        await learner.getByText(/^(Ожидает выдачи|Awaiting issue)$/).waitFor();
        await submit(learner.getByRole('button', {name: 'Подать заявку за 7 USCOIN'}), learner);

        await visit(manager, adminUrl);
        const first = manager.locator('article').filter({hasText: 'Термокружка для теста'}).first();
        await first.getByLabel('Причина отклонения').fill('Фикстура возврата');
        await submit(first.getByRole('button', {name: 'Отклонить и вернуть USCOIN'}), manager);
        await visit(manager, adminUrl);
        await submit(manager.getByRole('button', {name: 'Отметить выдачу'}), manager);
        await visit(learner, shopUrl);
        await learner.getByText('Доступно 13 USCOIN').waitFor();
        await learner.getByText(/^(Отклонена|Rejected)$/).waitFor();
        await learner.getByText(/^(Выдана|Issued)$/).waitFor();
        await learner.getByText('Фикстура возврата', {exact: true}).waitFor();
        fs.writeFileSync('artifacts/reward-shop-browser.json', JSON.stringify({
            result: 'PASS', closedBeforeDepartmentSelection: true, employeeViewport: 390,
            publishedRewardPurchased: true, adminIssued: true, adminRejectedAndRefunded: true,
        }, null, 2));
        console.log('REWARD_SHOP_NATIVE_BROWSER=PASS');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
