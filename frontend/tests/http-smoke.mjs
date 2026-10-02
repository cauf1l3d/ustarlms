// Exercises the built Next server; Moodle is an isolated HTTP fixture.
import {createServer} from 'node:http';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import assert from 'node:assert/strict';
import {encodeSession, SESSION_TTL_SECONDS} from '../src/lib/session-codec.ts';

const secret = 'http-fixture-only-session-secret-32-bytes';
let calls = 0;
const upstream = createServer(async (req, res) => {
  let raw = '';
  for await (const part of req) raw += part;
  const body = new URLSearchParams(raw);
  res.setHeader('content-type', 'application/json');
  if (req.url === '/login/token.php') {
    assert.equal(body.get('username'), 'fixture');
    assert.equal(body.get('password'), 'fixture-only');
    res.end(JSON.stringify({token:'fixture-personal-token'}));
  } else {
    calls++;
    assert.equal(body.get('wstoken'), 'fixture-personal-token');
    assert.equal(body.get('wsfunction'), 'local_ustar_get_workspace');
    res.end(JSON.stringify({json:JSON.stringify({fixture:true})}));
  }
});
upstream.listen(0, '127.0.0.1');
await once(upstream, 'listening');
const origin = 'http://127.0.0.1:3107';
const child = spawn(process.execPath, ['node_modules/next/dist/bin/next','start','--hostname','127.0.0.1','--port','3107'], {
  env:{...process.env, SESSION_SECRET:secret, APP_ORIGIN:origin,
    MOODLE_URL:`http://127.0.0.1:${upstream.address().port}`, NEXT_TELEMETRY_DISABLED:'1'},
  stdio:['ignore','pipe','pipe'],
});
let logs = '';
for (const stream of [child.stdout,child.stderr]) stream.on('data', chunk => {logs = (logs + chunk).slice(-8000);});
const post = (path, body, cookie, requestOrigin=origin) => fetch(origin+path, {
  method:'POST', headers:{origin:requestOrigin,'content-type':'application/json',...(cookie?{cookie}:{})},
  body:JSON.stringify(body), signal:AbortSignal.timeout(5000),
});
try {
  const deadline = Date.now()+30000;
  while (true) {
    try {await fetch(origin, {signal:AbortSignal.timeout(1000)}); break;}
    catch {if (child.exitCode !== null || Date.now()>deadline) throw new Error('Next did not start: '+logs); await new Promise(r=>setTimeout(r,200));}
  }
  assert.equal((await post('/api/auth/login', {username:'fixture',password:'fixture-only'},null,'https://other.invalid')).status,403);
  const login = await post('/api/auth/login', {username:'fixture',password:'fixture-only'});
  assert.equal(login.status,200);
  const header = login.headers.get('set-cookie');
  assert.match(header,/HttpOnly/i);
  assert.ok(!header.includes('fixture-personal-token'));
  const cookie = header.split(';')[0];
  assert.equal((await post('/api/ws/workspace',{})).status,401);
  assert.equal((await post('/api/ws/workspace',{},cookie,'https://other.invalid')).status,403);
  assert.equal((await post('/api/ws/constructor',{},cookie)).status,403);
  assert.equal((await post('/api/ws/workspace',{wstoken:'forged'},cookie)).status,502);
  assert.equal(calls,0);
  const allowed = await post('/api/ws/workspace',{},cookie);
  assert.equal(allowed.status,200);
  assert.deepEqual(await allowed.json(),{fixture:true});
  assert.equal(calls,1);
  const cookieName = cookie.split('=')[0];
  const expired = encodeSession('fixture-personal-token',secret,Date.now()-(SESSION_TTL_SECONDS+1)*1000);
  assert.equal((await post('/api/ws/workspace',{},`${cookieName}=${expired}`)).status,401);
  assert.equal((await post('/api/auth/logout',{},cookie,'https://other.invalid')).status,403);
  const logout = await post('/api/auth/logout',{},cookie);
  assert.equal(logout.status,200);
  assert.match(logout.headers.get('set-cookie'),/Max-Age=0|expires=Thu, 01 Jan 1970/i);
  console.log('BUILT_NEXT_HTTP_SMOKE=PASS (synthetic Moodle upstream)');
} finally {
  child.kill('SIGTERM');
  await Promise.race([once(child,'exit'),new Promise(r=>setTimeout(r,5000))]);
  if (child.exitCode === null) child.kill('SIGKILL');
  upstream.closeAllConnections();
  await new Promise(r=>upstream.close(r));
}
