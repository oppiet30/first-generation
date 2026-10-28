const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../public/assets/dashboard.js'), 'utf8');

async function load(response, options = {}) {
  const elements = new Map();
  const listeners = new Map();
  const requests = [];
  let refresh;
  const node = () => ({value: '', addEventListener() {}, append() {}, replaceChildren() {}, setAttribute() {},
    insertRow() { return {insertCell() { return {}; }}; }});
  const document = {getElementById(id) {
    if (!elements.has(id)) elements.set(id, {...node(), value: id === 'world' ? '1' : id === 'from' ? '0' : '',
      addEventListener(event, callback) { listeners.set(`${id}:${event}`,callback); }});
    return elements.get(id);
  }, createElementNS: node, createElement: node, querySelectorAll: () => [], hidden: false};
  const urls = [];
  vm.runInNewContext(source, {
    document, URLSearchParams, AbortController,
    location: {search: options.search || ''}, window: {addEventListener() {}},
    history: {replaceState(a,b,url) { urls.push(url); }},
    setInterval(callback, delay) { assert.equal(delay, 300000); refresh = callback; },
    fetch: async (url, init) => { requests.push({url, signal: init.signal}); return typeof response === 'function' ? response() : response; }
  });
  await new Promise(resolve => setImmediate(resolve));
  elements.controls = {refresh, requests, listeners, urls, document};
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

const report = day => ({ok: true, json: async () => ({
  world: {id: 1, name: 'Genesis World', current_day: day, status: 'running'}, from: 0, to: day,
  summary: {living: 2, births: 0, deaths: 0, generations: 1, range_births: 0, range_deaths: 0},
  daily: [], yearly: [], ages: [], death_ages: [], infants: [], generations: [], deaths: [], events: []
})});
const settle = () => new Promise(resolve => setImmediate(resolve));

test('five-minute refresh follows the latest day with a blank end filter', async () => {
  let day = 10;
  const elements = await load(() => report(day));
  day = 20;
  elements.controls.refresh(); await settle();
  assert.equal(elements.controls.requests.length, 2);
  assert.equal(elements.get('world-clock').textContent, 'Day 20');
  assert.equal(elements.get('to').value, '');
  assert.equal(new URLSearchParams(elements.controls.urls.at(-1).slice(1)).has('to'), false);
  assert.equal(new URL(elements.controls.requests[1].url, 'http://example.test').searchParams.has('to'), false);
});

test('automatic refresh retains an explicit world and historical range', async () => {
  const elements = await load(report(10), {search: '?world=2&from=3&to=8'});
  elements.controls.refresh(); await settle();
  const params = new URL(elements.controls.requests[1].url, 'http://example.test').searchParams;
  assert.equal(params.get('world'), '2'); assert.equal(params.get('from'), '3'); assert.equal(params.get('to'), '8');
});

test('refresh waits while filters are being edited or the page is hidden', async () => {
  const elements = await load(report(10));
  const {refresh, requests, listeners, document} = elements.controls;
  document.hidden = true; refresh(); await settle();
  assert.equal(requests.length, 1);
  document.hidden = false;
  listeners.get('filters:input')(); refresh(); await settle();
  assert.equal(requests.length, 1);
  listeners.get('filters:submit')({preventDefault() {}}); await settle();
  refresh(); await settle();
  assert.equal(requests.length, 3);
});

test('refresh does not overlap an in-flight request', async () => {
  let finish;
  const elements = await load(() => new Promise(resolve => { finish = resolve; }));
  elements.controls.refresh(); await settle();
  assert.equal(elements.controls.requests.length, 1);
  assert.equal(elements.controls.requests[0].signal.aborted, false);
  finish(report(10)); await settle();
});
