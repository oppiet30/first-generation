'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const colors = {teal: '#28665c', rust: '#b76248', gold: '#bf944d', muted: '#a4afa6'};
  const number = value => Number(value).toLocaleString('en-US');
  const ns = 'http://www.w3.org/2000/svg';
  const svgElement = (tag, attributes = {}, text) => {
    const node = document.createElementNS(ns, tag);
    for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  let loaded = null;
  let pending = null;
  let requestNumber = 0;
  let filtersEdited = false;
  const charts = [];
  function chart(id, rows, keys, labels, palette, label, kind = 'bar', patterned = false) {
    const container = $(id);
    let selected = -1;
    function draw() {
      const highContrast = $('chart-colors').value === 'contrast';
      const displayColors = highContrast ? ['#202020', '#8a8a8a'] : ['#0072b2', '#e69f00'];
      const wide = id === 'yearly-chart' && $('yearly-bars').value === 'wide';
      const deathOnly = id === 'death-chart' || id === 'infant-chart';
      palette = deathOnly ? [displayColors[1]] : displayColors;
      if (id === 'yearly-chart') container.style.minWidth = `${Math.max(260, rows.length * (wide ? 64 : 40))}px`;
      const width = Math.max(container.clientWidth, 260), height = container.clientHeight || 259;
      const left = 39, right = 14, top = 24, bottom = 41;
      const plotWidth = width - left - right, plotHeight = height - top - bottom;
      const svg = svgElement('svg', {viewBox: `0 0 ${width} ${height}`, 'aria-hidden': 'true'});
      if (patterned) {
        const defs = svgElement('defs');
        const pattern = svgElement('pattern', {id: `${id}-deaths`, patternUnits: 'userSpaceOnUse', width: 6, height: 6});
        pattern.append(svgElement('rect', {width: 6, height: 6, fill: deathOnly ? palette[0] : palette[1]}));
        pattern.append(svgElement('path', {d: 'M-1,1 L1,-1 M0,6 L6,0 M5,7 L7,5', stroke: '#182e2c', 'stroke-width': 1.5}));
        defs.append(pattern); svg.append(defs);
      }
      const maximum = rows.reduce((max, row) => Math.max(max, ...keys.map(key => Number(row[key]))), 0);
      const step = Math.max(1, Math.ceil(maximum / 4)), maxY = step * 4;
      const y = value => top + plotHeight * (1 - value / maxY);
      const x = index => left + (kind === 'line' ? (rows.length > 1 ? index / (rows.length - 1) : 0.5) : (index + 0.5) / Math.max(1, rows.length)) * plotWidth;
      for (let tick = 0; tick <= 4; tick++) {
        const value = tick * step;
        svg.append(svgElement('line', {x1: left, x2: width - right, y1: y(value), y2: y(value), stroke: '#e4e6dc', 'stroke-width': 1}));
        svg.append(svgElement('text', {x: left - 8, y: y(value) + 3, 'text-anchor': 'end', fill: '#67736c', 'font-size': 10}, number(value)));
      }
      const labelCount = Math.max(2, Math.floor(plotWidth / (kind === 'line' ? 65 : 38)));
      const every = Math.max(1, Math.ceil(rows.length / labelCount));
      rows.forEach((row, index) => {
        if (index % every === 0 || index === rows.length - 1) {
          if (index === rows.length - 1 && index > 0 && index % every < every / 2 && index % every !== 0) return;
          svg.append(svgElement('text', {x: x(index), y: height - 15, 'text-anchor': 'middle', fill: '#67736c', 'font-size': 9}, label(row)));
        }
      });
      keys.forEach((key, series) => {
        if (kind === 'line') {
          const points = rows.map((row, index) => `${index ? 'L' : 'M'}${x(index).toFixed(2)},${y(Number(row[key])).toFixed(2)}`).join('');
          if (rows.length) {
            svg.append(svgElement('path', {d: `${points}L${x(rows.length - 1)},${y(0)}L${x(0)},${y(0)}Z`, fill: palette[series], opacity: 0.07}));
            svg.append(svgElement('path', {d: points, fill: 'none', stroke: palette[series], 'stroke-width': 2, 'stroke-linejoin': 'round'}));
            if (rows.length === 1) svg.append(svgElement('circle', {cx: x(0), cy: y(Number(rows[0][key])), r: 3, fill: palette[series]}));
          }
        } else {
          const group = plotWidth / Math.max(1, rows.length), barWidth = Math.max(1, Math.min(wide ? 40 : 28, group * (wide ? 0.86 : 0.72) / keys.length));
          rows.forEach((row, index) => {
            const rect = svgElement('rect', {x: x(index) - barWidth * keys.length / 2 + series * barWidth,
              y: y(Number(row[key])), width: Math.max(1, barWidth - 2), height: y(0) - y(Number(row[key])),
              fill: patterned && (series === 1 || deathOnly) ? `url(#${id}-deaths)` : palette[series], rx: 1});
            rect.append(svgElement('title', {}, `${label(row)} · ${labels[series]}: ${number(row[key])}`));
            svg.append(rect);
          });
        }
      });
      const guide = svgElement('line', {x1: 0, x2: 0, y1: top, y2: y(0), stroke: '#182e2c', 'stroke-dasharray': '3 4', opacity: 0});
      svg.append(guide);
      const tooltip = document.createElement('div');
      tooltip.className = 'tooltip'; tooltip.hidden = true;
      tooltip.setAttribute('role', 'status');
      container.replaceChildren(svg, tooltip);
      if (!maximum) {
        const empty = document.createElement('div'); empty.className = 'chart-empty';
        empty.textContent = 'No observations in this view.'; container.append(empty);
      }
      function inspect(index) {
        if (!rows.length) return;
        selected = Math.max(0, Math.min(rows.length - 1, index));
        const row = rows[selected];
        tooltip.textContent = `${kind === 'line' ? 'Day ' : ''}${label(row)}\n${keys.map((key, i) => `${labels[i]}: ${number(row[key])}`).join(' · ')}`;
        tooltip.hidden = false; guide.setAttribute('x1', x(selected)); guide.setAttribute('x2', x(selected)); guide.setAttribute('opacity', '0.35');
      }
      svg.addEventListener('pointermove', event => {
        const rect = svg.getBoundingClientRect();
        const fraction = ((event.clientX - rect.left) / rect.width * width - left) / plotWidth;
        inspect(kind === 'line' ? Math.round(fraction * (rows.length - 1)) : Math.floor(fraction * rows.length));
      });
      container.onkeydown = event => {
        const actions = {ArrowLeft: selected - 1, ArrowRight: selected + 1, Home: 0, End: rows.length - 1};
        if (Object.hasOwn(actions, event.key)) { event.preventDefault(); inspect(actions[event.key]); }
      };
      container.onblur = () => { tooltip.hidden = true; guide.setAttribute('opacity', '0'); };
      if (selected >= 0) inspect(selected);
    }
    draw();
    charts.push({container, draw});
  }
  function renderYearly() {
    if (!loaded) return;
    const rows = $('yearly-window').value === 'all' ? loaded.yearly : loaded.yearly.slice(-Number($('yearly-window').value));
    const highContrast = $('chart-colors').value === 'contrast';
    const palette = highContrast ? ['#202020', '#8a8a8a'] : ['#0072b2', '#e69f00'];
    const partial = row => row.year > 0 && (row.from_day > (row.year-1)*365+1 || row.to_day < row.year*365);
    const first = rows[0], last = rows.at(-1);
    $('yearly-caption').textContent = rows.length
      ? `Years ${first.year}–${last.year}. * marks a partial year. Scroll horizontally for more years. CSV includes the entire selected day range.`
      : 'No years in the selected day range.';
    // Replace this chart's resize handler when a display preference changes.
    const previous = charts.findIndex(item => item.container === $('yearly-chart'));
    if (previous !== -1) charts.splice(previous, 1);
    chart('yearly-chart', rows, ['births', 'deaths'], ['Births', 'Deaths'], palette,
      row => `${row.year}${partial(row) ? '*' : ''}`, 'bar', true);
  }
  for (const [id, fallback, values] of [
    ['yearly-window', '20', ['10', '20', 'all']],
    ['chart-colors', 'color', ['color', 'contrast']],
    ['yearly-bars', 'wide', ['standard', 'wide']]
  ]) {
    const key = `first-generation-${id}`;
    let saved;
    try { saved = localStorage.getItem(key); } catch {}
    $(id).value = values.includes(saved) ? saved : fallback;
    $(id).addEventListener('change', () => {
      if (!values.includes($(id).value)) $(id).value = fallback;
      try { localStorage.setItem(key, $(id).value); } catch {}
      if (id === 'yearly-window' || id === 'yearly-bars') renderYearly();
      else if (loaded) render(loaded);
    });
  }
  function table(id, rows, cells, emptyMessage) {
    const body = $(id); body.replaceChildren();
    if (!rows.length) {
      const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = cells.length; cell.textContent = emptyMessage;
    } else for (const item of rows) {
      const row = body.insertRow();
      for (const cell of cells) row.insertCell().textContent = cell(item);
    }
  }
  function render(data) {
    loaded = data; charts.length = 0;
    const palette = $('chart-colors').value === 'contrast' ? ['#202020', '#8a8a8a'] : ['#0072b2', '#e69f00'];
    $('results').style.setProperty('--birth-color', palette[0]);
    $('results').style.setProperty('--death-color', palette[1]);
    $('results').hidden = false;
    $('world-name').textContent = data.world.name;
    $('world-clock').textContent = `Day ${number(data.world.current_day)}`;
    $('world-status').textContent = `World ${data.world.id} · ${data.world.status} · view ends day ${number(data.to)}`;
    for (const key of ['living', 'births', 'deaths', 'generations']) $(key).textContent = number(data.summary[key]);
    // A blank end day follows the simulation as it advances.
    $('range-caption').textContent = `Days ${number(data.from)}–${number(data.to)}, inclusive`;
    $('interval-summary').textContent = `${number(data.summary.range_births)} ${data.summary.range_births === 1 ? 'birth' : 'births'} · ${number(data.summary.range_deaths)} ${data.summary.range_deaths === 1 ? 'death' : 'deaths'} in range`;
    if (data.display?.daily_step > 1) $('interval-summary').textContent += ` · graph sampled every ${number(data.display.daily_step)} days`;
    $('death-caption').textContent = data.display && data.display.death_rows < data.display.death_rows_total
      ? `Showing ${number(data.display.death_rows)} of ${number(data.display.death_rows_total)} deaths in person ID order. Download CSV for all records.`
      : 'All deaths inside the selected range.';
    chart('population-chart', data.daily, ['living'], ['Living'], [colors.teal], row => number(row.day), 'line');
    renderYearly();
    chart('ages-chart', data.ages, ['female', 'male'], ['Female', 'Male'], [colors.teal, colors.gold], row => row.band);
    chart('death-chart', data.death_ages, ['count'], ['Deaths'], [colors.rust], row => row.band);
    chart('infant-chart', data.infants, ['count'], ['Deaths'], [colors.rust], row => row.band);
    chart('generation-chart', data.generations, ['living', 'deceased'], ['Living', 'Deceased'], [colors.teal, colors.muted], row => `Gen ${row.generation}`);
    table('death-rows', data.deaths, [p => `${p.name} (#${p.person_id})`, p => number(p.day),
      p => p.age_days < 365 ? `${p.age_days} ${p.age_days === 1 ? 'day' : 'days'}` : `${p.age_years} ${p.age_years === 1 ? 'year' : 'years'}`, p => p.cause], 'No deaths in this range.');
    table('event-rows', data.events, [e => number(e.day), e => e.event_type, e => e.person_id ? `${e.name || 'Unknown person'} (#${e.person_id})` : '—', e => e.details], 'No events in this range.');
    for (const link of document.querySelectorAll('[data-report]')) {
      const params = new URLSearchParams({world: data.world.id, from: data.from, to: data.to, format: 'csv', report: link.dataset.report});
      link.href = `api.php?${params}`;
    }
  }
  async function load(reset = false) {
    const id = ++requestNumber;
    if (pending) pending.abort();
    pending = new AbortController();
    const params = new URLSearchParams({world: $('world').value, from: reset ? '0' : $('from').value});
    if (!reset && $('to').value !== '') params.set('to', $('to').value);
    $('apply').disabled = true; $('message').textContent = 'Loading statistics…'; $('message').className = 'message';
    // Hide the previous world/range rather than showing stale graphs beside changed filters.
    $('results').hidden = true;
    loaded = null;
    try {
      const response = await fetch(`api.php?${params}`, {signal: pending.signal, cache: 'no-store'});
      // Apache can return an HTML error page before PHP handles the request.
      if (!response.ok && !(response.headers.get('content-type') || '').includes('application/json')) {
        throw new Error(`Statistics server returned HTTP ${response.status}. Try again later; if this continues, check Apache and PHP-FPM on the server.`);
      }
      let data;
      try {
        data = await response.json();
      } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error('The statistics server returned an invalid response. Check the API configuration and server logs.');
      }
      if (!response.ok) throw new Error(data.error || 'Statistics could not be loaded.');
      if (id !== requestNumber) return;
      if (reset) { $('from').value = 0; $('to').value = ''; }
      render(data); $('message').textContent = '';
      const urlParams = new URLSearchParams({world: data.world.id, from: data.from});
      if (params.has('to')) urlParams.set('to', data.to);
      history.replaceState(null, '', `?${urlParams}`);
    } catch (error) {
      if (error.name !== 'AbortError' && id === requestNumber) {
        $('message').textContent = error.message === 'Failed to fetch' ? 'Cannot reach the statistics server. Check the connection and try again.' : error.message;
        $('message').className = 'message error';
        $('world-name').textContent = `World ${$('world').value}`;
        $('world-clock').textContent = 'Unavailable';
        $('world-status').textContent = 'No statistics loaded';
        $('range-caption').textContent = 'No range loaded';
      }
    } finally { if (id === requestNumber) { $('apply').disabled = false; pending = null; } }
  }
  $('filters').addEventListener('input', () => { filtersEdited = true; });
  $('filters').addEventListener('submit', event => { event.preventDefault(); filtersEdited = false; load(); });
  $('reset').addEventListener('click', () => { filtersEdited = false; load(true); });
  const initial = new URLSearchParams(location.search);
  for (const key of ['world', 'from', 'to']) if (initial.has(key)) $(key).value = initial.get(key);
  let resizeTimer;
  window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => { if (loaded) for (const item of charts) item.draw(); }, 120); });
  load();
  const refreshKey = 'first-generation-refresh-minutes';
  const intervals = ['0', '5', '10', '15'];
  let refreshTimer;
  let refreshMinutes = '5';
  try {
    const saved = localStorage.getItem(refreshKey);
    if (intervals.includes(saved)) refreshMinutes = saved;
  } catch {} // Refresh still works when browser storage is unavailable.
  $('refresh').value = refreshMinutes;
  function scheduleRefresh() {
    clearInterval(refreshTimer);
    if ($('refresh').value === '0') return;
    refreshTimer = setInterval(() => {
      // Do not interrupt a request or apply filters the viewer is still editing.
      if (!document.hidden && !pending && !filtersEdited) load();
    }, Number($('refresh').value) * 60 * 1000);
  }
  $('refresh').addEventListener('change', () => {
    if (!intervals.includes($('refresh').value)) $('refresh').value = '5';
    try { localStorage.setItem(refreshKey, $('refresh').value); } catch {}
    scheduleRefresh();
  });
  scheduleRefresh();
})();
