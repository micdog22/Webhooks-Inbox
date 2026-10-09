let CSRF = null;
let chartRef = null;

const $ = sel => document.querySelector(sel);

async function getCSRF() {
  const r = await fetch('../api/csrf');
  const j = await r.json();
  CSRF = j.token;
}

function apiHeaders(json=false) {
  const h = { 'X-CSRF-Token': CSRF };
  if (json) h['Content-Type'] = 'application/json';
  return h;
}

function fmtInt(n){ return Number(n).toLocaleString('pt-BR'); }
function originIncoming(token){ return location.origin + '/api/incoming/' + token; }

async function loadStats() {
  const r = await fetch('../api/stats');
  const j = await r.json();
  $('#totalChannels').textContent = fmtInt(j.totalChannels);
  $('#totalEvents').textContent = fmtInt(j.totalEvents);

  if (j.series?.length) {
    const labels = j.series.map(s => s.day);
    const data = j.series.map(s => Number(s.events));
    if (chartRef) chartRef.destroy();
    const ctx = $('#chart').getContext('2d');
    chartRef = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: [{ label: 'Eventos por dia (14d)', data }] },
      options: { responsive: true, maintainAspectRatio: false }
    });
  }
}

async function loadChannels() {
  const r = await fetch('../api/channels');
  const j = await r.json();
  const tbody = document.querySelector('#tblChannels tbody');
  tbody.innerHTML='';

  const sel = $('#filterChannel');
  sel.innerHTML = '<option value="">Todos</option>';

  if (j.items.length) {
    $('#sampleEndpoint').textContent = originIncoming(j.items[0].token);
  } else {
    $('#sampleEndpoint').textContent = '-';
  }

  j.items.forEach(ch => {
    const tr = document.createElement('tr');
    const ep = originIncoming(ch.token);
    tr.innerHTML = `
      <td>${ch.name}</td>
      <td><code>${ch.token}</code></td>
      <td><code>${ep}</code></td>
      <td class="actions">
        <button data-act="copy">Copiar</button>
        <button data-act="del" class="danger">Excluir</button>
      </td>`;
    tr.querySelector('[data-act="copy"]').onclick = async () => {
      await navigator.clipboard.writeText(ep);
      alert('Endpoint copiado: ' + ep);
    };
    tr.querySelector('[data-act="del"]').onclick = async () => {
      if (!confirm('Excluir canal e todos os eventos?')) return;
      const r = await fetch(`../api/channels/${ch.id}`, { method: 'DELETE', headers: apiHeaders() });
      if (!r.ok) { alert('Erro ao excluir canal'); return; }
      await Promise.all([loadChannels(), loadEvents(), loadStats()]);
    };
    tbody.appendChild(tr);

    const opt = document.createElement('option');
    opt.value = ch.id; opt.textContent = ch.name;
    sel.appendChild(opt);
  });
}

function filtersQS() {
  const p = new URLSearchParams();
  const c = $('#filterChannel').value;
  const f = $('#from').value;
  const t = $('#to').value;
  const q = $('#q').value.trim();
  if (c) p.set('channel_id', c);
  if (f) p.set('from', f);
  if (t) p.set('to', t);
  if (q) p.set('q', q);
  return p.toString();
}

async function loadEvents() {
  const r = await fetch('../api/events?' + filtersQS());
  const j = await r.json();
  const tbody = document.querySelector('#tblEvents tbody');
  tbody.innerHTML='';
  j.items.forEach(ev => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${ev.created_at}</td>
      <td>${ev.channel_name}</td>
      <td>${ev.method}</td>
      <td>${ev.path || ''}</td>
      <td class="actions">
        <button data-act="view">Ver</button>
        <button data-act="del" class="danger">Excluir</button>
      </td>`;
    tr.querySelector('[data-act="view"]').onclick = () => openEvent(ev.id);
    tr.querySelector('[data-act="del"]').onclick = async () => {
      if (!confirm('Excluir este evento?')) return;
      const r = await fetch(`../api/events/${ev.id}`, { method: 'DELETE', headers: apiHeaders() });
      if (!r.ok) { alert('Erro ao excluir evento'); return; }
      await loadEvents();
      await loadStats();
    };
    tbody.appendChild(tr);
  });
}

function prettyMaybeJSON(text) {
  try {
    return JSON.stringify(JSON.parse(text), null, 2);
  } catch { return text; }
}

async function openEvent(id) {
  const r = await fetch(`../api/events/${id}`);
  const j = await r.json();
  const it = j.item;

  $('#evHeader').textContent = `${it.created_at} • ${it.method} ${it.path || ''} • IP ${it.ip || ''}`;
  $('#evHeaders').textContent = prettyMaybeJSON(it.headers || '');
  $('#evQuery').textContent = prettyMaybeJSON(it.query || '');
  $('#evBody').textContent = prettyMaybeJSON(it.body || '');

  const f = document.querySelector('#formReplay');
  f.id.value = it.id;
  f.method.value = it.method;
  f.headers.value = JSON.stringify({ 'Content-Type': it.content_type || 'application/json' }, null, 2);
  f.bodyMode.value = 'original';
  f.rawBody.value = '';

  document.querySelector('#dlgEvent').showModal();
}

async function createChannel(ev) {
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  const data = Object.fromEntries(fd.entries());

  const r = await fetch('../api/channels', {
    method: 'POST',
    headers: apiHeaders(true),
    body: JSON.stringify(data)
  });
  const j = await r.json();
  if (!r.ok) { alert('Erro:\n' + JSON.stringify(j, null, 2)); return; }
  ev.currentTarget.reset();
  await Promise.all([loadChannels(), loadStats()]);
}

async function replay(ev) {
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  const id = fd.get('id');
  const bodyMode = fd.get('bodyMode');
  let headers;
  try { headers = JSON.parse(fd.get('headers') || '{}'); }
  catch { alert('Headers inválidos. Use JSON.'); return; }

  const payload = {
    url: fd.get('url'),
    method: fd.get('method'),
    headers,
    bodyMode
  };
  if (bodyMode === 'raw') payload.rawBody = fd.get('rawBody') || '';

  const r = await fetch(`../api/events/${id}/replay`, {
    method: 'POST',
    headers: apiHeaders(true),
    body: JSON.stringify(payload)
  });
  const j = await r.json();
  if (!r.ok) { alert('Erro no replay:\n' + JSON.stringify(j, null, 2)); return; }

  let info = '';
  if (j.status) info = 'Status: ' + j.status + '\n\n' + (j.responseHeaders || '') + '\n\n' + (j.responseBody || '');
  else info = (j.statusLine || '') + '\n\n' + (j.responseBody || '');
  alert(info.slice(0, 5000));
}

function bindUI() {
  document.querySelector('#formChannel').addEventListener('submit', createChannel);
  document.querySelector('#btnFilter').onclick = () => loadEvents();
  document.querySelector('#btnClear').onclick = () => {
    $('#filterChannel').value = '';
    $('#from').value = '';
    $('#to').value = '';
    $('#q').value = '';
    loadEvents();
  };
  document.querySelector('#btnExport').onclick = () => {
    const url = '../api/export/events?' + filtersQS();
    window.open(url, '_blank');
  };
  document.querySelector('#btnCloseEvent').onclick = () => document.querySelector('#dlgEvent').close();
  document.querySelector('#formReplay').addEventListener('submit', replay);
}

(async function init(){
  await getCSRF();
  bindUI();
  await loadChannels();
  await loadEvents();
  await loadStats();
})();
