'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const {performance} = require('node:perf_hooks');
const {chromium} = require('playwright');

const base = 'http://127.0.0.1:8087';
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const resultPath = process.argv[3];
const afterReassignment = process.argv[4] === 'reassign';
const password = 'Fixture-Only-Password1!';

async function login(browser, username, viewport) {
    const context = await browser.newContext({viewport});
    const page = await context.newPage();
    page.on('pageerror', error => { page.errors.push(error.message); });
    page.errors = [];
    await page.goto(base + '/login/index.php');
    await page.locator('input[name="username"]').fill(username);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('#loginbtn').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login/index.php'), {timeout: 15000});
    const response = await page.goto(base + '/local/ustar/messages.php');
    assert.equal(response.status(), 200);
    await page.locator('[data-chat-root]').waitFor();
    const sesskey = await page.locator('[data-chat-root]').getAttribute('data-sesskey');
    assert.ok(sesskey);
    return {context, page, sesskey};
}

async function post(actor, fields, endpoint = 'messages_api.php') {
    const body = new URLSearchParams({sesskey: actor.sesskey});
    for (const [key, value] of Object.entries(fields)) {
        if (Array.isArray(value)) value.forEach(item => body.append(key, String(item)));
        else body.set(key, String(value));
    }
    return actor.context.request.post(base + '/local/ustar/' + endpoint, {
        data: body.toString(), headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json'},
    });
}

async function metrics(actor, params, count = 16) {
    const times = [];
    let bytes = 0;
    for (let index = 0; index < count; index++) {
        const start = performance.now();
        const response = await post(actor, params);
        assert.equal(response.status(), 200);
        const raw = await response.body();
        if (params.delta) {
            const parsed = JSON.parse(raw.toString());
            assert.equal(parsed.html, '');
            assert.equal(parsed.ids.length, 50);
        }
        times.push(performance.now() - start);
        bytes += raw.length;
    }
    times.sort((a, b) => a - b);
    return {requests: count, bytes: bytes / count, p50_ms: Math.round(times[Math.floor(count / 2)]),
        p95_ms: Math.round(times[Math.ceil(count * 0.95) - 1])};
}

async function compose(actor, text, files = []) {
    const form = actor.page.locator('[data-chat-compose]');
    await form.locator('textarea').fill(text);
    if (files.length) await form.locator('input[type=file]').setInputFiles(files);
    const response = actor.page.waitForResponse(reply =>
        reply.url().endsWith('/local/ustar/messages_api.php') && reply.request().method() === 'POST'
        && reply.request().headers()['content-type']?.startsWith('multipart/form-data;'));
    await form.getByRole('button', {name: 'Отправить', exact: true}).click();
    const reply = await response;
    assert.equal(reply.status(), 200, await reply.text());
    await actor.page.locator('[data-chat-items]').getByText(text, {exact: true}).waitFor();
}

async function fixtureImage(page) {
    const source = await page.evaluate(() => {
        const canvas = document.createElement('canvas');
        canvas.width = 600;
        canvas.height = 550;
        const ctx = canvas.getContext('2d');
        const img = ctx.createImageData(canvas.width, canvas.height);
        let value = 83121;
        for (let index = 0; index < img.data.length; index++) {
            value = (Math.imul(value, 1664525) + 1013904223) >>> 0;
            img.data[index] = index % 4 === 3 ? 255 : value >>> 24;
        }
        ctx.putImageData(img, 0, 0);
        return canvas.toDataURL('image/png').split(',')[1];
    });
    return Buffer.from(source, 'base64');
}

async function main() {
    const executablePath = process.env.USTAR_TEST_CHROMIUM || undefined;
    const browser = await chromium.launch({headless: true, executablePath, args: ['--no-sandbox']});
    try {
        const member = await login(browser, 'work_member', {width: 390, height: 844});
        if (afterReassignment) {
            const data = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
            const denied = await post(member, {action: 'poll', taskid: fixture.task, delta: 1});
            assert.equal(denied.status(), 400);
            assert.equal((await member.context.request.get(data.download)).status(), 404);
            assert.equal((await member.context.request.get(data.thumbnail)).status(), 404);
            await member.page.goto(base + '/local/ustar/messages.php?taskchats=1');
            assert.equal(await member.page.getByText('Workplace review fixture', {exact: true}).count(), 0);
            const newOwner = await login(browser, 'work_colleague');
            assert.equal((await post(newOwner, {action: 'poll', taskid: fixture.task, delta: 1})).status(), 200);
            assert.equal((await newOwner.context.request.get(data.download)).status(), 200);
            console.log('WORKPLACE_HTTP_TASK_ACCESS_REVOKED=OK');
            return;
        }

        const admin = await login(browser, 'admin', {width: 1440, height: 1000});
        const outsider = await login(browser, 'work_outsider');
        let response = await admin.page.goto(base + '/local/ustar/messages.php?q=Archive+pilot');
        assert.equal(response.status(), 200);
        await admin.page.getByRole('link', {name: /Archive pilot group/}).click();
        const ids = await admin.page.locator('[data-chat-items] > [data-message-id]').evaluateAll(nodes =>
            nodes.map(node => Number(node.dataset.messageId)));
        assert.equal(ids.length, 50);
        const delta = await metrics(admin, {action: 'poll', conversationid: fixture.chat, delta: 1, 'known[]': ids});
        const full = await metrics(admin, {action: 'poll', conversationid: fixture.chat});
        assert.ok(delta.bytes < full.bytes * 0.1, 'Idle polling should not retransmit history');
        await admin.page.getByRole('link', {name: /Предыдущие сообщения/}).click();
        const earlier = await admin.page.locator('[data-chat-items] > [data-message-id]').evaluateAll(nodes =>
            nodes.map(node => Number(node.dataset.messageId)));
        assert.equal(earlier.length, 50);
        assert.ok(earlier.every(id => !ids.includes(id)));
        await admin.page.locator('[name=find]').fill('archive-search-key');
        await admin.page.locator('.u-chat-thread-search').getByRole('button', {name: 'Найти'}).click();
        assert.equal(await admin.page.locator('[data-chat-items] > [data-message-id]').count(), 1);
        await admin.page.getByText(/History line 700/).waitFor();

        // A new task discussion reuses the composer and updates an open peer without replacing the previous DOM.
        const taskurl = base + '/local/ustar/tasks.php?taskid=' + fixture.task;
        await member.page.goto(taskurl);
        await member.page.locator('#uw-discussion').scrollIntoViewIfNeeded();
        await admin.page.goto(taskurl);
        await admin.page.locator('#uw-discussion').scrollIntoViewIfNeeded();
        await compose(admin, 'Fixture from the task creator');
        await member.page.locator('[data-chat-items]').getByText('Fixture from the task creator', {exact: true}).waitFor({timeout: 25000});
        const first = member.page.locator('[data-chat-items] > [data-message-id]').first();
        await first.evaluate(node => { node.dataset.fixtureStable = 'true'; });
        const image = await fixtureImage(admin.page);
        assert.ok(image.length > 256 * 1024 && image.length < 4 * 1024 * 1024);
        const file = Buffer.alloc(3 * 1024 * 1024, 0x61);
        await compose(admin, 'Fixture files', [
            {name: 'workplace-large.txt', mimeType: 'text/plain', buffer: file},
            {name: 'preview-fixture.png', mimeType: 'image/png', buffer: image},
        ]);
        await member.page.locator('[data-chat-items]').getByText('Fixture files', {exact: true}).waitFor({timeout: 25000});
        assert.equal(await first.getAttribute('data-fixture-stable'), 'true');
        await compose(admin, 'Fixture duplicate content', [{name: 'workplace-large.txt', mimeType: 'text/plain', buffer: file}]);

        const thumbnail = new URL(await admin.page.locator('img[alt="preview-fixture.png"]').getAttribute('src'), base).href;
        const download = new URL(await admin.page.getByRole('link', {name: /workplace-large.txt/}).first().getAttribute('href'), base).href;
        const previewData = await member.context.request.get(thumbnail);
        assert.equal(previewData.status(), 200);
        const previewBytes = (await previewData.body()).length;
        assert.ok(previewBytes < image.length * 0.4, 'A bounded thumbnail should be smaller than the original');
        assert.ok(!previewData.headers()['cache-control'].includes('86400'));
        assert.deepEqual(await (await member.context.request.get(download)).body(), file);
        assert.equal((await outsider.context.request.get(thumbnail)).status(), 404);
        const denied = await post(outsider, {action: 'poll', taskid: fixture.task, delta: 1});
        assert.equal(denied.status(), 400);
        assert.doesNotMatch(await denied.text(), /Fixture files|preview-fixture\.png/);
        const mixed = await post(member, {action: 'poll', taskid: fixture.task, conversationid: fixture.chat, delta: 1});
        assert.equal(mixed.status(), 400);

        await admin.page.locator('#uw-discussion').getByRole('link', {name: 'Назначить встречу'}).click();
        const edit = admin.page.locator('form.uw-form').filter({has: admin.page.locator('[data-meeting-check]')});
        assert.equal(await edit.locator('[name=meetingtitle]').inputValue(), 'Workplace review fixture');
        assert.equal(await edit.locator('[name="participants[]"][value="' + fixture.member + '"]').isChecked(), true);
        await edit.locator('[name=starttime]').fill(fixture.date + 'T11:10');
        await edit.locator('[name=endtime]').fill(fixture.date + 'T11:40');
        await edit.locator('[name=roomid]').selectOption(String(fixture.room));
        await edit.getByRole('button', {name: 'Проверить занятость'}).click();
        await edit.locator('[role=status]').filter({hasText: 'Переговорная занята'}).waitFor();
        assert.match(await edit.locator('[role=status]').textContent(), /Заняты Work Member/);
        assert.doesNotMatch(await edit.locator('[role=status]').textContent(), /Private agenda|Private appointment name/);
        const cannotCheck = await post(outsider, {starttime: fixture.date + 'T11:10', endtime: fixture.date + 'T11:40',
            roomid: fixture.room, 'participants[]': [fixture.member]}, 'meeting_availability.php');
        assert.equal(cannotCheck.status(), 403);
        await edit.locator('[name=meetingtitle]').fill('New workplace meeting');
        await edit.locator('[name=starttime]').fill(fixture.date + 'T14:00');
        await edit.locator('[name=endtime]').fill(fixture.date + 'T15:00');
        await edit.getByRole('button', {name: 'Проверить занятость'}).click();
        await edit.locator('[role=status]').filter({hasText: 'Пересечений по выбранному времени нет'}).waitFor();
        await edit.getByRole('button', {name: 'Отправить приглашения'}).click();
        await admin.page.getByRole('heading', {name: 'New workplace meeting'}).waitFor();
        await member.page.goto(base + '/local/ustar/tasks.php?view=calendar&month=' + fixture.date.slice(0, 7));
        await member.page.locator('.uw-calendar').getByRole('link', {name: /New workplace meeting/}).waitFor();

        await member.page.goto(base + '/local/ustar/tasks.php?view=plan');
        const board = member.page.locator('.uw-plan-board');
        await board.getByRole('heading', {name: 'Без плана'}).waitFor();
        await member.page.getByText('Добавить колонку', {exact: true}).click();
        const add = member.page.locator('.uw-plan-create');
        await add.locator('[name=columntitle]').fill('На сегодня');
        await add.getByRole('button', {name: 'Добавить', exact: true}).click();
        const lane = board.getByRole('region', {name: 'На сегодня'});
        const key = await lane.getAttribute('data-board-column');
        const card = board.locator('.uw-board-card').filter({hasText: 'Workplace review fixture'});
        await card.locator('select[name=column]').selectOption(key);
        await card.getByRole('button', {name: 'Переместить'}).click();
        await lane.getByRole('link', {name: 'Workplace review fixture'}).waitFor();
        assert.match(await lane.innerText(), /Назначена/);
        assert.ok(await member.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Phone page should not overflow');
        assert.deepEqual(admin.page.errors, []);
        assert.deepEqual(member.page.errors, []);

        const result = {moodle_http: true, browser_task_chat_group_search_plan_meeting: 'ok',
            fixture_history_rows: 700, full_poll: full, delta_poll: delta,
            image_original_bytes: image.length, preview_bytes: previewBytes, download, thumbnail};
        fs.writeFileSync(resultPath, JSON.stringify(result, null, 2) + '\n', {mode: 0o600});
        console.log(JSON.stringify({...result, download: undefined, thumbnail: undefined}));
    } finally {
        await browser.close();
    }
}

main().catch(error => { console.error(error); process.exit(1); });
