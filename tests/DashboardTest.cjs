const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../public/assets/dashboard.js'), 'utf8');

async function load(response) {
  const elements = new Map();
  const document = {getElementById(id) {
    if (!elements.has(id)) elements.set(id, {value: id === 'world' ? '1' : '', addEventListener() {}});
    return elements.get(id);
  }};
  vm.runInNewContext(source, {
    document, URLSearchParams, AbortController,
    location: {search: ''}, window: {addEventListener() {}},
    fetch: async () => response
  });
  await new Promise(resolve => setImmediate(resolve));
  return elements;
}

test('HTML 503 shows the HTTP error without parsing HTML as JSON', async () => {
  const elements = await load({ok: false, status: 503,
    headers: {get: () => 'text/html; charset=iso-8859-1'},
    json() { assert.fail('must not parse the HTML error page'); }
  });
  assert.match(elements.get('message').textContent, /HTTP 503/);
  assert.match(elements.get('message').textContent, /PHP-FPM/);
  assert.equal(elements.get('world-clock').textContent, 'Unavailable');
  assert.equal(elements.get('results').hidden, true);
  assert.equal(elements.get('apply').disabled, false);
});

test('JSON API errors retain the server message', async () => {
  const elements = await load({ok: false, status: 404,
    headers: {get: () => 'application/json; charset=utf-8'},
    json: async () => ({error: 'World not found.'})
  });
  assert.equal(elements.get('message').textContent, 'World not found.');
});

test('invalid successful responses show an actionable message', async () => {
  const elements = await load({ok: true, json: async () => {
    throw new SyntaxError('Unexpected token <');
  }});
  assert.match(elements.get('message').textContent, /invalid response/);
  assert.equal(elements.get('apply').disabled, false);
});

test('network failure retains the connection guidance', async () => {
  const elements = await load({get ok() { throw new TypeError('Failed to fetch'); }});
  assert.match(elements.get('message').textContent, /Cannot reach the statistics server/);
});
