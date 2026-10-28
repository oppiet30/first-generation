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
  const timers = new Map();
  const storage = new Map(Object.entries(options.preferences || {}));
  if (options.saved !== undefined) storage.set('first-generation-refresh-minutes', options.saved);
  let nextTimer = 0;
  const node = () => ({value: '', style: {setProperty(key,value) {this[key] = value;}}, children: [], attributes: {}, clientWidth: 600, clientHeight: 259,
    addEventListener() {}, append(...children) {this.children.push(...children);}, replaceChildren(...children) {this.children = children;}, setAttribute(key,value) {this.attributes[key] = value;},
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
    localStorage: {getItem(key) { if (options.storageBlocked) throw new Error('Blocked'); return storage.get(key) ?? null; },
      setItem(key,value) { if (options.storageBlocked) throw new Error('Blocked'); storage.set(key,value); }},
    setInterval(callback, delay) { refresh = callback; timers.set(++nextTimer, {callback, delay}); return nextTimer; },
    clearInterval(id) { timers.delete(id); },
    fetch: async (url, init) => { requests.push({url, signal: init.signal}); return typeof response === 'function' ? response() : response; }
  });
  await new Promise(resolve => setImmediate(resolve));
  elements.controls = {refresh, requests, listeners, urls, document, timers, storage};
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

test('selector replaces the timer and Off disables automatic refresh', async () => {
  const elements = await load(report(10));
  const {timers, listeners, storage} = elements.controls;
  const change = listeners.get('refresh:change');
  assert.equal([...timers.values()][0].delay, 300000);
  for (const minutes of ['10','15','5']) {
    elements.get('refresh').value = minutes; change();
    assert.equal(timers.size, 1);
    assert.equal([...timers.values()][0].delay, Number(minutes)*60000);
    assert.equal(storage.get('first-generation-refresh-minutes'), minutes);
  }
  elements.get('refresh').value = '0'; change();
  assert.equal(timers.size, 0);
  assert.equal(storage.get('first-generation-refresh-minutes'), '0');
});

test('saved preference is restored, including Off', async () => {
  for (const saved of ['0','5','10','15']) {
    const elements = await load(report(10), {saved});
    assert.equal(elements.get('refresh').value, saved);
    assert.equal(elements.controls.timers.size, saved === '0' ? 0 : 1);
    if (saved !== '0') assert.equal([...elements.controls.timers.values()][0].delay, Number(saved)*60000);
  }
});

test('invalid or unavailable browser storage falls back to five minutes', async () => {
  for (const options of [{saved:'bad'},{storageBlocked:true}]) {
    const elements = await load(report(10), options);
    assert.equal(elements.get('refresh').value, '5');
    assert.equal([...elements.controls.timers.values()][0].delay, 300000);
    elements.get('refresh').value = '0'; elements.controls.listeners.get('refresh:change')();
    assert.equal(elements.controls.timers.size, 0);
  }
});

const yearlyReport = async () => {
  const response = report(10930);
  const data = await response.json();
  data.yearly = Array.from({length:30}, (_,i) => ({year:i+1,from_day:i*365+1,to_day:(i+1)*365-(i === 29 ? 20 : 0),births:i+10,deaths:i+2}));
  data.ages = [{band:'0–9',female:3,male:2}];
  data.generations = [{generation:1,living:3,deceased:2}];
  return {ok:true,json:async () => data};
};
const bars = element => element.children[0].children.filter(child => Object.hasOwn(child.attributes,'rx'));

test('yearly chart offers wider 10/20/full views and marks incomplete years', async () => {
  const elements = await load(yearlyReport);
  const {listeners,requests,storage} = elements.controls;
  assert.equal(bars(elements.get('yearly-chart')).length, 40);
  assert.equal(elements.get('yearly-chart').style.minWidth, '1280px');
  assert.match(elements.get('yearly-caption').textContent, /Years 11–30/);
  assert.ok(elements.get('yearly-chart').children[0].children.some(child => child.textContent === '30*'));
  for (const [value,count] of [['10',20],['all',60],['20',40]]) {
    elements.get('yearly-window').value=value;
    listeners.get('yearly-window:change')();
    assert.equal(bars(elements.get('yearly-chart')).length,count);
    assert.equal(storage.get('first-generation-yearly-window'),value);
  }
  elements.get('yearly-bars').value='standard'; listeners.get('yearly-bars:change')();
  assert.equal(elements.get('yearly-chart').style.minWidth,'800px');
  assert.equal(elements.get('ages-chart').style.minWidth,undefined);
  assert.equal(elements.get('generation-chart').style.minWidth,undefined);
  assert.equal(requests.length,1,'Display choices do not fetch or change the selected data range');
});

test('colors apply across charts while stripes remain specific to the yearly chart', async () => {
  const elements = await load(yearlyReport);
  const yearlyBars=bars(elements.get('yearly-chart'));
  assert.equal(yearlyBars[0].attributes.fill,'#0072b2');
  assert.equal(yearlyBars.at(-1).attributes.fill,'url(#yearly-chart-deaths)');
  assert.deepEqual(bars(elements.get('ages-chart')).map(bar => bar.attributes.fill),['#0072b2','#e69f00']);
  elements.get('chart-colors').value='contrast'; elements.controls.listeners.get('chart-colors:change')();
  assert.deepEqual(bars(elements.get('ages-chart')).map(bar => bar.attributes.fill),['#202020','#8a8a8a']);
  assert.equal(elements.controls.storage.get('first-generation-chart-colors'),'contrast');
  assert.equal(elements.controls.requests.length,1);
});

test('graph display preferences restore independently of auto-refresh', async () => {
  const elements = await load(yearlyReport,{saved:'0',preferences:{
    'first-generation-chart-colors':'contrast','first-generation-yearly-window':'10','first-generation-yearly-bars':'standard'
  }});
  assert.equal(elements.get('chart-colors').value,'contrast');
  assert.equal(bars(elements.get('yearly-chart')).length,20);
  assert.equal(elements.get('yearly-chart').style.minWidth,'400px');
  assert.equal(elements.controls.timers.size,0);
});
