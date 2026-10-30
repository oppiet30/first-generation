(() => {
  'use strict';
  const el = id => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const integer = value => /^\d+$/.test(value || '') && Number.isSafeInteger(Number(value)) && Number(value)>0;
  el('family-world').value = integer(params.get('world')) ? params.get('world') : '1';
  let current = null, next = null, sequence = 0;
  const trail = [];
  const number = value => Number(value).toLocaleString();
  function message(text, error = false) { el('family-message').textContent = text; el('family-message').classList.toggle('error',error); }
  function card(person, selected = false) {
    const box = document.createElement(selected ? 'article' : 'button');
    if (!selected) { box.type = 'button'; box.addEventListener('click',() => open(person.id)); }
    box.className = `person-card${selected ? ' selected' : ''}${person.death_day === null ? '' : ' deceased'}`;
    box.dataset.person = person.id;
    const lines = [person.name,`#${person.id} · ${person.sex === 'female' ? 'Female' : 'Male'}`,
      person.death_day === null ? `Living · age ${person.age_years}` : `Deceased · age ${person.age_years} at death`,
      `Born day ${number(person.birth_day)}${person.initial_age_days ? ' · founder, initial age '+Math.floor(person.initial_age_days/365) : ''}`,
      person.death_day === null ? '' : `Died day ${number(person.death_day)}`,
      `Mother: ${person.mother_id === null ? 'not recorded' : '#'+person.mother_id} · Father: ${person.father_id === null ? 'not recorded' : '#'+person.father_id}`];
    lines.filter(Boolean).forEach((text,i) => { const line = document.createElement(i === 0 ? 'strong' : 'span'); line.textContent = text; if(i===2) line.className='person-status'; box.append(line); });
    if (!selected) box.setAttribute('aria-label',`Explore ${person.name}, person ${person.id}`);
    return box;
  }
  function row(id, people, empty, selected = false) {
    const nodes = people.map(person => card(person,selected));
    if (!nodes.length) { const text=document.createElement('p'); text.className='tree-empty'; text.textContent=empty; nodes.push(text); }
    el(id).replaceChildren(...nodes);
  }
  function drawLines() {
    const canvas=el('tree-canvas'), svg=el('tree-lines'), center=el('tree-person').firstElementChild;
    svg.replaceChildren();
    if (!current || !center || el('family-view').hidden) return;
    const bounds=canvas.getBoundingClientRect(), scale=Number(el('tree-zoom').value);
    svg.setAttribute('width',canvas.offsetWidth); svg.setAttribute('height',canvas.offsetHeight);
    const point=(node,bottom) => {const r=node.getBoundingClientRect();return [(r.left+r.width/2-bounds.left)/scale,((bottom?r.bottom:r.top)-bounds.top)/scale];};
    const connect=(a,b) => {const path=document.createElementNS('http://www.w3.org/2000/svg','path');const y=(a[1]+b[1])/2;path.setAttribute('d',`M ${a[0]} ${a[1]} V ${y} H ${b[0]} V ${b[1]}`);svg.append(path);};
    el('tree-parents').querySelectorAll('.person-card').forEach(node=>connect(point(node,true),point(center,false)));
    el('tree-children').querySelectorAll('.person-card').forEach(node=>connect(point(center,true),point(node,false)));
  }
  async function request(query, render) {
    const ticket=++sequence;
    message('Loading family records…');
    el('family-view').setAttribute('aria-busy','true');
    try {
      const response=await fetch(`family-api.php?${query}`,{headers:{Accept:'application/json'}});
      let data;
      try { data=await response.json(); } catch { throw new Error(`Family server returned HTTP ${response.status} without readable data.`); }
      if(!response.ok) throw new Error(data.error || `Family server returned HTTP ${response.status}.`);
      if(ticket!==sequence) return;
      render(data);
    } catch(error) { if(ticket===sequence) { message(error.message,true); el('family-view').hidden=true; el('search-results').hidden=true; } }
    finally { if(ticket===sequence) el('family-view').setAttribute('aria-busy','false'); }
  }
  function world() { return el('family-world').value; }
  function open(id = null, after = 0, remember = true) {
    const query=new URLSearchParams({world:world()}); if(id!==null) query.set('person',id); if(after) query.set('after',after);
    return request(query,data=>{
      if(remember && current && (current.id!==data.person.id || current.world!==world())) trail.push(current);
      current={id:data.person.id,world:world()}; next=data.next_after;
      el('search-results').hidden=true; el('family-view').hidden=false;
      row('tree-parents',data.parents,'No parents recorded.'); row('tree-person',[data.person],'',true); row('tree-children',data.children,'No children recorded.');
      el('tree-caption').textContent=`${data.world.name} · day ${number(data.world.current_day)} · ${data.person.name} (#${data.person.id})`;
      el('children-caption').textContent=`${data.children.length} children shown${after ? ' · later page' : ''}${next ? ' · more available' : ''}.`;
      el('more-children').hidden=next===null; el('first-children').hidden=!after; el('previous-person').disabled=!trail.length;
      el('statistics-link').href=`./?world=${encodeURIComponent(world())}`;
      history.replaceState(null,'',`family-tree.html?world=${encodeURIComponent(world())}&person=${data.person.id}`);
      message('Select a relative to explore their family.');
      requestAnimationFrame(()=>{drawLines();const viewport=el('tree-canvas').parentElement;viewport.scrollLeft=Math.max(0,(viewport.scrollWidth-viewport.clientWidth)/2);});
    });
  }
  el('family-search').addEventListener('submit',event=>{
    event.preventDefault(); const text=el('family-query').value.trim();
    if(!text) return open();
    const id=text.replace(/^#/,''); if(integer(id)) return open(id);
    return request(new URLSearchParams({world:world(),search:text}),data=>{
      el('family-view').hidden=true; el('search-results').hidden=false;
      el('search-results').replaceChildren(...data.matches.map(person=>card(person)));
      message(data.matches.length ? `${data.matches.length} matches${data.more ? ' · enter more of the name to narrow the results' : ''}. Select a person.` : 'No matching people. Search uses the beginning of a name.');
    });
  });
  el('founder').addEventListener('click',()=>{el('family-query').value='';open();});
  el('family-world').addEventListener('input',()=>{++sequence; current=null; trail.length=0; el('family-view').hidden=true; el('search-results').hidden=true; message('Choose Find person to load this world.');});
  el('more-children').addEventListener('click',()=>{if(current&&next!==null) open(current.id,next,false);});
  el('first-children').addEventListener('click',()=>{if(current) open(current.id,0,false);});
  el('previous-person').addEventListener('click',()=>{const prior=trail.pop();if(prior){el('family-world').value=prior.world;open(prior.id,0,false);}});
  el('tree-zoom').addEventListener('change',()=>{el('tree-canvas').style.setProperty('--tree-zoom',el('tree-zoom').value);requestAnimationFrame(drawLines);});
  window.addEventListener('resize',drawLines);
  open(integer(params.get('person')) ? params.get('person') : null);
})();
