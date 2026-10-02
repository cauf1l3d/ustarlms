import test from 'node:test';
import assert from 'node:assert/strict';
import { encodeSession, decodeSession, SESSION_TTL_SECONDS } from '../src/lib/session-codec.ts';
import { assertSameOrigin, readJsonObject, readBoundedBody } from '../src/lib/request-security.ts';

const secret = 'fixture-only-secret-material-32-bytes';
test('session is confidential, expires on the server and supports revocation', () => {
  const now = 100000, token = 'fixture-personal-token';
  const cookie = encodeSession(token, secret, now);
  assert.equal(decodeSession(cookie, secret, now), token);
  assert.ok(!cookie.includes(token));
  assert.ok(!Buffer.from(cookie.split('.')[2], 'base64url').toString().includes(token));
  assert.notEqual(encodeSession(token, secret, now), cookie);
  assert.equal(decodeSession(cookie, secret, now + SESSION_TTL_SECONDS * 1000), null);
  assert.equal(decodeSession(cookie, secret, now - 1), null);
  assert.equal(decodeSession(cookie, secret + '-rotated', now), null);
});
test('tampered, old signed, malformed and oversized cookies are rejected', () => {
  const parts = encodeSession('fixture', secret, 100).split('.');
  const ciphertext = Buffer.from(parts[2], 'base64url');
  ciphertext[0] ^= 1; parts[2] = ciphertext.toString('base64url');
  for (const invalid of [parts.join('.'), 'old-payload.old-signature', 'v1..x.y', 'x'.repeat(9000)]) {
    assert.equal(decodeSession(invalid, secret, 100), null);
  }
  for (const invalidSecret of ['', 'short', 'dev-secret-change-me']) {
    assert.throws(() => encodeSession('fixture', invalidSecret));
    assert.throws(() => decodeSession('anything', invalidSecret));
  }
});
test('mutations require same origin; streamed oversized JSON is refused', async () => {
  const url = 'https://academy.invalid/api/ws/team';
  assert.doesNotThrow(() => assertSameOrigin(new Request(url, {headers:{origin:'https://academy.invalid'}})));
  assert.throws(() => assertSameOrigin(new Request(url)), {status:403});
  assert.throws(() => assertSameOrigin(new Request(url, {headers:{origin:'https://other.invalid'}})), {status:403});
  const req = body => new Request(url, {method:'POST',headers:{'content-type':'application/json'},body});
  assert.deepEqual(await readJsonObject(req('{"userid":9}')), {userid:9});
  await assert.rejects(readJsonObject(req('[]')), {status:400});
  await assert.rejects(readJsonObject(req('{')), {status:400});
  await assert.rejects(readBoundedBody(req('123456'), 5), {status:413});
});
test('proxy preserves the personal token and rejects all reserved selectors before fetching', async () => {
  process.env.MOODLE_URL = 'https://moodle.invalid';
  const {moodleCall, moodleFileUrl} = await import('../src/lib/moodle.ts');
  const previous = globalThis.fetch;
  let calls = 0, sent;
  globalThis.fetch = async (url, init) => {calls++; sent = init; return new Response('{"ok":true}');};
  try {
    for (const name of ['wstoken','wsfunction','moodlewsrestformat','WSTOKEN','wstoken[]']) {
      await assert.rejects(moodleCall('fixture-personal', 'local_ustar_get_team', {[name]:'override'}));
    }
    assert.equal(calls, 0);
    assert.deepEqual(await moodleCall('fixture-personal', 'local_ustar_get_team', {userid:9}), {ok:true});
    assert.equal(sent.body.get('wstoken'), 'fixture-personal');
    assert.equal(sent.body.get('wsfunction'), 'local_ustar_get_team');
    assert.equal(sent.body.get('userid'), '9');
    assert.ok(sent.signal);
    assert.throws(() => moodleFileUrl('https://other.invalid/pluginfile.php/x', 'fixture-personal'));
  } finally { globalThis.fetch = previous; }
});
