(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const csrf = $('meta[name="csrf-token"]').content;
  const user = Number(document.body.dataset.user);
  const post = async (url, values) => {
    const response = await fetch(url, {method:'POST',headers:{'X-CSRF-Token':csrf},body:new URLSearchParams(values)});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Please try again.');
    return data;
  };
  $('[data-dismiss]')?.addEventListener('click', () => $('.flash').remove());
  $('[data-back]')?.addEventListener('click', () => history.back());
  $('.menu-toggle')?.addEventListener('click', function () { const open = $('.main-nav').classList.toggle('open'); this.setAttribute('aria-expanded', String(open)); });
  $$('[data-purpose]').forEach(button => button.addEventListener('click', () => { $$('[data-purpose]').forEach(b => b.classList.toggle('selected', b === button)); $('#hero-purpose').value = button.dataset.purpose; }));
  $$('[data-view]').forEach(button => button.addEventListener('click', () => { $$('[data-view]').forEach(b => b.classList.toggle('selected', b === button)); $('.map-view').hidden = button.dataset.view !== 'map'; }));
  $$('[data-marker-index]').forEach(marker => { const i = Number(marker.dataset.markerIndex); marker.style.left = `${12 + (i * 29) % 58}%`; marker.style.top = `${23 + (i * 19) % 61}%`; marker.addEventListener('mouseenter', () => { const card = $(`[data-property="${marker.dataset.mapProperty}"]`); if (card) card.style.outline = '2px solid #b29461'; }); marker.addEventListener('mouseleave', () => { const card = $(`[data-property="${marker.dataset.mapProperty}"]`); if (card) card.style.outline = ''; }); });
  $('[data-sort]')?.addEventListener('change', function () { const url = new URL(location.href); url.searchParams.set('sort', this.value); url.searchParams.delete('page'); location.assign(url); });
  $$('[data-timezone]').forEach(input => { if (input.value === 'UTC') input.value = Intl.DateTimeFormat().resolvedOptions().timeZone; });
  let comparisons;
  try { comparisons = JSON.parse(localStorage.getItem('aurelle-comparison') || '[]').filter(n => Number.isInteger(n)).slice(0,4); } catch { comparisons = []; }
  const updateComparison = () => { localStorage.setItem('aurelle-comparison', JSON.stringify(comparisons)); $('.compare-bar').hidden = comparisons.length === 0; $('[data-compare-count]').textContent = comparisons.length; $('[data-compare-link]').href = `/compare?ids=${comparisons.join(',')}`; $$('[data-compare]').forEach(box => box.checked = comparisons.includes(Number(box.dataset.compare))); };
  $$('[data-compare]').forEach(box => box.addEventListener('change', () => { const id = Number(box.dataset.compare); if (box.checked && comparisons.length >= 4) { box.checked = false; alert('Compare up to four homes. Remove one to add another.'); return; } comparisons = box.checked ? [...comparisons,id] : comparisons.filter(n => n !== id); updateComparison(); }));
  $('[data-clear-compare]')?.addEventListener('click', () => { comparisons = []; updateComparison(); }); updateComparison();
  $$('[data-calculator]').forEach(form => { const calculate = () => { const v = Object.fromEntries(new FormData(form)); const principal = Math.max(0,Number(v.price)-Number(v.deposit)); const r = Number(v.rate)/1200; const n = Number(v.years)*12; const payment = n > 0 ? (r > 0 ? principal*r/(1-Math.pow(1+r,-n)) : principal/n) + (Number(v.taxes)+Number(v.insurance))/12 : 0; $('[data-payment]',form).textContent = Number.isFinite(payment) ? payment.toLocaleString('en-US',{style:'currency',currency:'USD',maximumFractionDigits:0}) : 'Check assumptions'; }; form.addEventListener('input',calculate); form.addEventListener('submit',e => e.preventDefault()); calculate(); });
  const chat = $('[data-chat]');
  if (!chat) return;
  const conversation = Number(chat.dataset.chat);
  const box = $('.chat-messages',chat);
  const transport = $('[data-transport]',chat);
  const error = $('.chat-error',chat);
  const compose = $('.chat-compose',chat);
  let socket, last = 0, first = 0, reconnect, searchMode = false, connected = false, inFlight = false, draftKey = null, lastTyping = 0;
  const rendered = new Set();
  function showMessage(message, prepend = false) {
    const id = Number(message.id);
    if (rendered.has(id)) { const existing = $(`[data-message="${id}"]`,box); if (existing && Number(message.sender_id) === user) $('small',existing).textContent = `#${id} · ${message.created_at} UTC · ${Number(message.read_count)>0?'Read':Number(message.delivered_count)>0?'Delivered':'Sent'}`; return; }
    rendered.add(id); last = Math.max(last,id); first = first ? Math.min(first,id) : id;
    const bubble = document.createElement('div'); bubble.className = `message ${Number(message.sender_id) === user ? 'mine' : ''}`; bubble.dataset.message = id;
    const sender = document.createElement('strong'); sender.textContent = message.sender_name || (Number(message.sender_id) === user ? 'You' : 'Participant');
    const text = document.createElement('span'); text.textContent = message.body;
    const meta = document.createElement('small'); meta.textContent = `#${id} · ${message.created_at} UTC${Number(message.sender_id) === user ? ` · ${Number(message.read_count)>0?'Read':Number(message.delivered_count)>0?'Delivered':'Sent'}` : ''}`;
    bubble.append(sender,text,meta); prepend ? box.prepend(bubble) : box.append(bubble);
  }
  async function receipts() { if (!last || searchMode) return; await post('/api/receipt',{conversation,last,read:document.visibilityState === 'visible'?'1':'0'}); }
  async function recover(refresh = false) {
    if (inFlight || searchMode) return;
    inFlight = true;
    try { const response = await fetch(`/api/messages?conversation=${conversation}&after=${refresh?0:last}`); const messages = await response.json(); if (!response.ok) throw new Error(messages.error); const nearBottom = box.scrollHeight-box.scrollTop-box.clientHeight < 100; messages.forEach(m => showMessage(m)); if (nearBottom) box.scrollTop = box.scrollHeight; await receipts(); error.textContent = ''; if (messages.length === 100 && last && !refresh) { inFlight = false; await recover(); } } catch (e) { error.textContent = e.message; } finally { inFlight = false; }
  }
  async function connect() {
    try { const response = await fetch('/api/token'); const info = await response.json(); if (!response.ok) throw new Error(info.error); socket = new WebSocket(info.url); socket.onopen = () => socket.send(JSON.stringify({type:'auth',token:info.token,conversation}));
      socket.onmessage = async event => { const payload = JSON.parse(event.data); if (payload.type === 'ready') { connected = true; transport.textContent = 'Live · WebSocket connected'; await recover(); } if (payload.type === 'message' && !searchMode) { showMessage(payload.message); box.scrollTop = box.scrollHeight; await receipts(); } if (payload.type === 'receipts' && !searchMode) payload.messages.forEach(m=>{if(rendered.has(Number(m.id)))showMessage(m);}); if (payload.type === 'typing') { $('[data-typing]',chat).textContent = 'A participant is typing…'; setTimeout(() => $('[data-typing]',chat).textContent = '',2500); } if (payload.type === 'presence') $('[data-typing]',chat).textContent = payload.online ? 'A participant is online (presence shared).' : ''; if (payload.type === 'error') error.textContent = payload.error; };
      socket.onclose = () => { connected = false; transport.textContent = 'Near-real-time polling · worker unavailable'; clearTimeout(reconnect); reconnect = setTimeout(connect,10000); }; socket.onerror = () => socket.close();
    } catch { transport.textContent = 'Near-real-time polling · worker unavailable'; clearTimeout(reconnect); reconnect = setTimeout(connect,10000); }
  }
  compose.addEventListener('submit', async event => {
    event.preventDefault(); const body = compose.body.value.trim(); if (!body) return;
    const button = $('button',compose); button.disabled = true; error.textContent = '';
    draftKey ??= crypto.randomUUID();
    try { const message = await post('/api/messages',{conversation,body,key:draftKey}); showMessage({...message,sender_name:'You'}); compose.body.value = ''; draftKey = null; box.scrollTop = box.scrollHeight; }
    catch (e) { error.textContent = e.message + ' Your draft is preserved. Retry uses the same send key.'; }
    finally { button.disabled = false; }
  });
  compose.body.addEventListener('input', () => { if (connected && Date.now()-lastTyping > 2000) { lastTyping = Date.now(); socket.send(JSON.stringify({type:'typing'})); } });
  $('[data-chat-search]',chat).addEventListener('click',async () => { const q = $('[data-message-search]',chat).value.trim(); if (!q) return; try { const r=await fetch(`/api/messages?conversation=${conversation}&q=${encodeURIComponent(q)}`); const messages=await r.json(); if(!r.ok) throw new Error(messages.error); searchMode=true; box.replaceChildren(); rendered.clear(); messages.forEach(m=>showMessage(m)); } catch(e){error.textContent=e.message;} });
  $('[data-chat-recent]',chat).addEventListener('click',async () => { searchMode=false; box.replaceChildren(); rendered.clear(); first=last=0; await recover(true); box.scrollTop=box.scrollHeight; });
  $('[data-chat-older]',chat).addEventListener('click',async () => { if(!first || searchMode) return; try { const r=await fetch(`/api/messages?conversation=${conversation}&before=${first}`); const messages=await r.json(); if(!r.ok) throw new Error(messages.error); [...messages].reverse().forEach(m=>showMessage(m,true)); } catch(e){error.textContent=e.message;} });
  document.addEventListener('visibilitychange',() => { if(document.visibilityState==='visible') recover(true); });
  setInterval(() => recover(),5000);
  setInterval(() => recover(true),15000);
  setInterval(async () => { if(!connected) return; try { const response=await fetch('/api/token'); const info=await response.json(); if(response.ok) socket.send(JSON.stringify({type:'auth',token:info.token,conversation})); } catch {} },180000);
  recover(true).then(()=>{box.scrollTop=box.scrollHeight;}); connect();
})();
