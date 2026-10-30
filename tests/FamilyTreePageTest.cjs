const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const source = readFileSync(path.join(__dirname,'../public/assets/family-tree.js'),'utf8');
const person = id => ({id,name:id===1 ? '<script>literal</script>' : 'Child '+id,sex:'male',birth_day:0,initial_age_days:7300,death_day:null,age_years:20,mother_id:null,father_id:null});
const neighborhood = (id=1) => ({world:{id:1,name:'World 1',current_day:0},person:person(id),parents:[],children:[],next_after:null});
const settle = () => new Promise(resolve=>setImmediate(resolve));
async function page(respond) {
  const elements=new Map(), requests=[];
  function node() {
    return {value:'',hidden:false,children:[],dataset:{},listeners:{},classList:{toggle(){}},style:{setProperty(){}},
      addEventListener(event,fn){this.listeners[event]=fn;},setAttribute(){},append(...items){this.children.push(...items);},replaceChildren(...items){this.children=items;},
      get firstElementChild(){return this.children[0];},querySelectorAll(){return this.children.filter(n=>n.className?.includes('person-card'));},
      getBoundingClientRect(){return {left:0,top:0,bottom:100,width:224};},parentElement:{scrollWidth:600,clientWidth:600}};
  }
  const el=id=>{if(!elements.has(id))elements.set(id,node());return elements.get(id);};
  el('tree-zoom').value='1';
  vm.runInNewContext(source,{URLSearchParams,location:{search:''},history:{replaceState(){}},window:{addEventListener(){}},requestAnimationFrame:fn=>fn(),
    document:{getElementById:el,createElement:node,createElementNS:node},fetch:async url=>{requests.push(url);return respond(url);}});
  await settle();
  return {el,requests,click:async id=>{await el(id).listeners.click();await settle();},submit:async text=>{el('family-query').value=text;await el('family-search').listeners.submit({preventDefault(){}});await settle();}};
}
const ok=data=>({ok:true,status:200,json:async()=>data});
test('family cards keep names as text and direct person IDs select relatives',async()=>{
  const p=await page(url=>ok(neighborhood(Number(new URLSearchParams(url.split('?')[1]).get('person')||1))));
  assert.equal(p.el('tree-person').children[0].children[0].textContent,'<script>literal</script>');
  await p.submit('#23');
  assert.match(p.requests.at(-1),/person=23/);
  assert.equal(p.el('tree-person').children[0].dataset.person,23);
  await p.click('previous-person');
  assert.match(p.requests.at(-1),/person=1/);
});
test('next children uses the supplied cursor and first children clears it',async()=>{
  const p=await page(()=>ok({...neighborhood(),children:[person(2)],next_after:22}));
  await p.click('more-children'); assert.match(p.requests.at(-1),/after=22/);
  await p.click('first-children'); assert.doesNotMatch(p.requests.at(-1),/after=/);
});
test('name search renders bounded results and explains narrowing',async()=>{
  const p=await page(url=>ok(url.includes('search=')?{matches:[person(2)],more:true}:neighborhood()));
  await p.submit('Child'); assert.match(p.requests.at(-1),/search=Child/);
  assert.equal(p.el('search-results').children.length,1);
  assert.match(p.el('family-message').textContent,/narrow/);
  assert.equal(p.el('family-view').hidden,true);
});
test('non-JSON server errors and network failures hide stale relatives',async()=>{
  const p=await page(()=>({ok:false,status:503,json:async()=>{throw Error('HTML');}}));
  assert.match(p.el('family-message').textContent,/HTTP 503/);
  assert.equal(p.el('family-view').hidden,true);
  const failed=await page(()=>{throw Error('Connection failed');});
  assert.equal(failed.el('family-message').textContent,'Connection failed');
});
test('world edits discard an older pending response',async()=>{
  let resolve;
  const p=await page(()=>new Promise(done=>{resolve=done;}));
  p.el('family-world').value='2'; p.el('family-world').listeners.input();
  resolve(ok(neighborhood())); await settle();
  assert.equal(p.el('family-view').hidden,true);
  assert.match(p.el('family-message').textContent,/load this world/);
});
