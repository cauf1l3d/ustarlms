const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('../mobile/node_modules/playwright');

const base = 'http://127.0.0.1:8087';
const adminUrl = base + '/local/ustar/reward_shop_admin.php';
const shopUrl = base + '/local/ustar/reward_shop.php';
const cover = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAMCAIAAADkharWAAAAF0lEQVR4nGMMXdTGQApgIkn1qIYRpAEAhfoBld3yUxcAAAAASUVORK5CYII=';
const replacement = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAMCAIAAADkharWAAAAF0lEQVR4nGPcUb6LgRTARJLqUQ0jSAMAfXACAYnfWYwAAAAASUVORK5CYII=';

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

async function coverUrl(image) {
    const width = await image.evaluate(async element => {
        await element.decode();
        return element.naturalWidth;
    });
    assert.equal(width, 16);
    return await image.evaluate(element => element.src);
}

async function assertLayout(page, card, action) {
    await action.scrollIntoViewIfNeeded();
    const outer = await card.boundingBox();
    const inner = await action.boundingBox();
    assert.ok(inner.height >= 44);
    assert.ok(inner.x - outer.x >= 12 && outer.x + outer.width - inner.x - inner.width >= 12);
    assert.ok(outer.y + outer.height - inner.y - inner.height >= 12);
    assert.ok(await page.locator('.u-rewards').evaluate(el => el.scrollWidth <= el.clientWidth + 1));
}

function imageUpload(bytes) {
    return {name: 'reward.png', mimeType: 'image/png', buffer: Buffer.from(bytes, 'base64')};
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
        await manager.getByLabel('Добавить фото').setInputFiles(imageUpload(cover));
        await submit(manager.getByRole('button', {name: 'Сохранить карточку'}), manager);
        const editorUrl = manager.url();
        const firstCover = await coverUrl(manager.getByAltText('Текущее фото награды'));

        await visit(learner, shopUrl);
        assert.equal(await learner.getByText('Термокружка для теста', {exact: true}).count(), 0);
        await learner.getByText('Сейчас витрина недоступна').waitFor();
        assert.equal((await employee.request.get(firstCover)).status(), 404);
        await visit(manager, adminUrl + '?section=settings');
        await manager.getByLabel('Открыть витрину выбранным отделам').check();
        await manager.getByLabel('Отдел проверки наград', {exact: true}).check();
        await submit(manager.getByRole('button', {name: 'Сохранить доступ'}), manager);

        await visit(learner, base + '/local/ustar/achievements.php');
        await learner.getByRole('link', {name: 'Магазин наград', exact: true}).click();
        await learner.getByText('Доступно 20 USCOIN').waitFor();
        const card = learner.locator('.u-rewards__card').filter({hasText: 'Термокружка для теста'});
        const action = card.getByRole('button', {name: 'Подать заявку за 7 USCOIN'});
        assert.equal(await coverUrl(card.locator('img')), firstCover);
        await assertLayout(learner, card, action);
        const light = await card.evaluate(el => getComputedStyle(el).backgroundColor);
        await learner.evaluate(() => localStorage.setItem('ustar-theme', 'dark'));
        await learner.setViewportSize({width: 1366, height: 900});
        await visit(learner, shopUrl);
        assert.equal(await learner.locator('html').getAttribute('data-ustar-theme'), 'dark');
        assert.notEqual(await card.evaluate(el => getComputedStyle(el).backgroundColor), light);
        await assertLayout(learner, card, action);
        await submit(action, learner);
        await visit(learner, shopUrl);
        await learner.getByText('Доступно 13 USCOIN').waitFor();
        await learner.getByText(/^(Ожидает выдачи|Awaiting issue)$/).waitFor();
        await submit(action, learner);
        await visit(learner, shopUrl);
        await assertLayout(learner, card, card.getByText('Нет в наличии', {exact: true}));

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

        await visit(manager, editorUrl);
        await manager.getByLabel('Цена в USCOIN').fill('20');
        await manager.getByLabel('Заменить фото').setInputFiles(imageUpload(replacement));
        await submit(manager.getByRole('button', {name: 'Сохранить карточку'}), manager);
        const newCover = await coverUrl(manager.getByAltText('Текущее фото награды'));
        assert.notEqual(newCover, firstCover);
        assert.equal((await employee.request.get(firstCover)).status(), 404);
        await learner.setViewportSize({width: 390, height: 844});
        await visit(learner, shopUrl);
        assert.equal(await coverUrl(card.locator('img')), newCover);
        await assertLayout(learner, card, card.getByText('Не хватает 7 USCOIN', {exact: true}));
        await manager.getByLabel('Публикация').selectOption('archived');
        await submit(manager.getByRole('button', {name: 'Сохранить карточку'}), manager);
        assert.equal(await coverUrl(manager.getByAltText('Текущее фото награды')), newCover);
        assert.equal((await employee.request.get(newCover)).status(), 404);
        await manager.getByLabel('Удалить текущее фото').check();
        await manager.getByLabel('Публикация').selectOption('published');
        await submit(manager.getByRole('button', {name: 'Сохранить карточку'}), manager);
        assert.equal(await manager.getByAltText('Текущее фото награды').count(), 0);
        await visit(learner, shopUrl);
        await card.locator('.u-rewards__media svg').waitFor();
        assert.equal((await employee.request.get(newCover)).status(), 404);

        await visit(manager, base + '/local/ustar/connect_announcements.php');
        await manager.getByText('Новая публикация с файлами', {exact: true}).click();
        await manager.getByLabel('Подразделение', {exact: true}).selectOption('reward_fixture');
        await manager.getByLabel('Текст объявления').fill('Файлы для команды');
        await manager.getByLabel('Файлы', {exact: true}).setInputFiles(imageUpload(cover));
        await submit(manager.getByRole('button', {name: 'Опубликовать и открыть предпросмотр'}), manager);
        await manager.getByText('reward.png', {exact: true}).waitFor();
        await manager.getByText('Отправка сейчас закрыта.', {exact: false}).waitFor();
        const post = await manager.getByRole('link', {name: 'Открыть публикацию в Ленте'}).getAttribute('href');
        await visit(learner, post);
        await learner.getByText('Файлы для команды', {exact: true}).waitFor();
        await visit(learner, base + '/local/ustar/achievements.php');
        const ranking = learner.getByRole('region', {name: 'Рейтинг отдела'});
        await ranking.getByText('Отдел проверки наград · XP за всё время', {exact: true}).waitFor();
        await ranking.getByText(/Тестовый.*Вы|Сотрудник.*Вы/).waitFor();
        const fixture = JSON.parse(fs.readFileSync('artifacts/reward-shop-fixture.json', 'utf8'));
        await visit(manager, base + '/local/ustar/team_learning.php?userid=' + fixture.employee);
        await manager.getByRole('heading', {name: 'Достижения', exact: true}).waitFor();
        await manager.getByRole('heading', {name: 'Навыки по должности', exact: true}).waitFor();
        fs.writeFileSync('artifacts/reward-shop-browser.json', JSON.stringify({
            result: 'PASS', closedBeforeDepartmentSelection: true, employeeViewports: [390, 1366],
            publishedRewardPurchased: true, adminIssued: true, adminRejectedAndRefunded: true,
            coversUploadedReplacedRemovedAndRestricted: true, lightAndDarkActionBounds: true,
            announcementFileUploadedWithSendingOff: true, departmentRankingAndTeamProfileRendered: true,
        }, null, 2));
        console.log('REWARD_SHOP_NATIVE_BROWSER=PASS');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
