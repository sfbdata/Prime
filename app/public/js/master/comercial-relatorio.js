/* BlueJus · Painel do Master, seção "Comercial e e-mails", "Relatório como caixa de entrada" da Central de envios (F3d)
   (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, marcação L300-305 e L502-514,
   lógica L698-717; spec docs/specs/comercial-relatorio.md).
   - A lista vem do servidor (GET data-url-lista?aba=&q=&p=): a contagem das 3 abas, a faixa "1 a 50 de N" e as
     linhas com o selo do estado, o público e o total, o nome e o subtítulo, a leitura (✓ cinza enviado, ✓ verde
     recebido, ✓✓ verde com o %: aberto e lido; o tooltip com os números) e a data ("14:30" hoje, "21 out" outro dia).
   - A busca espera 300 ms entre teclas e volta à página 1. A seleção vale para a página na tela.
   - Clicar na linha abre o visualizador (GET data-url-campanha): Voltar, Duplicar, Cancelar envio (só quando o
     servidor diz `cancelavel`), os 4 KPIs, o HTML num iframe sandbox="" (srcdoc) e os destinatários com o estado.
   - "Duplicar" é só no cliente. Contrato com o editor da campanha: dispara no document o evento cancelável
       `mc:duplicar-campanha`, detail { assunto, titulo, mensagem }.
     O editor que tratar o evento chama `preventDefault()` e preenche a si mesmo. Se ninguém tratar, o relatório
     preenche os campos [data-mc-cp-campo="assunto"] e [data-mc-cp-campo="titulo"] com um evento `input` (o editor
     atual escuta `input` neles) e rola até o editor.
   - "Cancelar envio" pede confirmação e manda POST com o token no header X-CSRF-Token; a recusa vem em {erro}.
   - Recarrega quando a seção abre e depois de cada "Enviar campanha" aceito (evento `mc:campanha-confirmada`). */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mc-relatorio]');
  if (!raiz) return;

  const MARCADOR = '9999999999';
  const el = s => raiz.querySelector(s);
  const lista = el('[data-mc-rl-lista]');
  const ver = el('[data-mc-rl-ver]');
  const busca = el('[data-mc-rl-busca]');
  const estado = { aba: 'env', q: '', p: 1, paginas: 1, ids: [], sel: {}, aberta: null };
  let carregado = false;
  let espera = 0;

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const url = (base, id) => base.replace(MARCADOR, String(id));

  function pedir(endereco, opcoes) {
    return fetch(endereco, Object.assign({ headers: { Accept: 'application/json' }, credentials: 'same-origin' }, opcoes || {}))
      .then(r => r.json().catch(() => ({})).then(d => (r.ok ? d : Promise.reject(new Error(d.erro || 'Não foi possível concluir. Recarregue a página.')))));
  }

  function avisar(texto) {
    const caixa = el('[data-mc-rl-msg]');
    el('[data-mc-rl-msg-texto]').textContent = texto || '';
    caixa.hidden = !texto;
  }

  // dc L702: "14:30" se for hoje; senão "21 out".
  function quando(iso) {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    if (d.toDateString() === new Date().toDateString()) return d.toLocaleTimeString('pt-BR').slice(0, 5);
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' }).replace('.', '');
  }

  function linha(c) {
    const m = c.metricas || {};
    const le = m.leitura === 'lido' ? 'mc-rl-le-lido' : m.leitura === 'entregue' ? 'mc-rl-le-entregue' : 'mc-rl-le-enviado';
    const icone = m.leitura === 'lido' ? 'bi-check2-all' : 'bi-check2';
    const marcada = !!estado.sel[c.id];
    return '<div class="mc-rl-linha' + (marcada ? ' mc-rl-marcada' : '') + '" data-mc-rl-id="' + esc(c.id) + '" title="Abrir">'
      + '<button type="button" class="mc-rl-sel" data-mc-rl-sel aria-label="Selecionar" aria-pressed="' + marcada + '"><i class="bi ' + (marcada ? 'bi-check-square-fill' : 'bi-square') + '" aria-hidden="true"></i></button>'
      + '<span class="mc-rl-publico">' + esc(c.publico) + ' <span class="mc-rl-total">' + esc(c.total) + '</span></span>'
      + '<span class="mc-rl-meio"><span class="mc-rl-st mc-rl-st-' + esc(c.estado) + '">' + esc(c.rotulo) + '</span>'
      + '<span class="mc-rl-nome"><b>' + esc(c.nome) + '</b><span class="mc-rl-sub"> · ' + esc(c.sub) + '</span></span></span>'
      + '<span class="mc-rl-le ' + le + '" title="' + esc(m.dica) + '"><i class="bi ' + icone + '" aria-hidden="true"></i>' + esc(m.percentual) + '</span>'
      + '<span class="mc-rl-data">' + esc(quando(c.data)) + '</span>'
      + '</div>';
  }

  function desenharSelecao() {
    const n = estado.ids.filter(i => estado.sel[i]).length;
    const ic = el('[data-mc-rl-sel-ic]');
    ic.className = 'bi ' + (n && n === estado.ids.length ? 'bi-check-square-fill' : n ? 'bi-dash-square' : 'bi-square');
    const rotulo = el('[data-mc-rl-sel-n]');
    rotulo.textContent = n + ' selecionada(s)';
    rotulo.hidden = n === 0;
    lista.querySelectorAll('[data-mc-rl-id]').forEach(l => {
      const marcada = !!estado.sel[l.dataset.mcRlId];
      l.classList.toggle('mc-rl-marcada', marcada);
      const b = l.querySelector('[data-mc-rl-sel]');
      b.setAttribute('aria-pressed', String(marcada));
      b.firstElementChild.className = 'bi ' + (marcada ? 'bi-check-square-fill' : 'bi-square');
    });
  }

  function desenhar(d) {
    raiz.querySelectorAll('[data-mc-rl-aba]').forEach(b => {
      const k = b.dataset.mcRlAba;
      b.setAttribute('aria-selected', String(k === estado.aba));
      const n = b.querySelector('[data-mc-rl-n]');
      const qtd = (d.abas || {})[k] || 0;
      n.textContent = String(qtd);
      n.hidden = qtd === 0;
    });
    estado.paginas = d.paginas || 1;
    estado.p = d.pagina || 1;
    el('[data-mc-rl-faixa]').textContent = d.faixa || '0 de 0';
    el('[data-mc-rl-pagina="-1"]').disabled = estado.p <= 1;
    el('[data-mc-rl-pagina="1"]').disabled = estado.p >= estado.paginas;
    const campanhas = d.campanhas || [];
    estado.ids = campanhas.map(c => String(c.id));
    lista.innerHTML = campanhas.length
      ? campanhas.map(linha).join('')
      : '<div class="mc-rl-vazio"><i class="bi bi-inbox" aria-hidden="true"></i>Nenhuma campanha nesta aba.</div>';
    lista.removeAttribute('aria-busy');
    desenharSelecao();
  }

  function carregar() {
    const q = new URLSearchParams({ aba: estado.aba, q: estado.q, p: String(estado.p) });
    lista.setAttribute('aria-busy', 'true');
    return pedir(raiz.dataset.urlLista + '?' + q.toString())
      .then(d => { carregado = true; desenhar(d); })
      .catch(() => { lista.removeAttribute('aria-busy'); lista.innerHTML = '<div class="mc-rl-vazio">Não foi possível carregar as campanhas. Recarregue a página.</div>'; });
  }

  // ---- Visualizador (dc L502-514) ----
  function texto(campo, valor) {
    const alvo = ver.querySelector('[data-mc-rl-v="' + campo + '"]');
    if (alvo) alvo.textContent = valor == null ? '' : String(valor);
  }

  function preencher(c) {
    estado.aberta = c;
    const de = String(c.de || '');
    const corte = de.indexOf(' <');
    texto('assunto', c.assunto);
    texto('situacao', c.situacao);
    texto('nome', corte > 0 ? de.slice(0, corte) : de);
    texto('endereco', corte > 0 ? de.slice(corte + 1) : '');
    texto('publico', c.publico);
    texto('modelo', c.modelo);
    texto('quando', c.quando);
    ver.querySelector('[data-mc-rl-cancelar]').hidden = !c.cancelavel;
    ver.querySelector('[data-mc-rl-kpis]').innerHTML = (c.kpis || []).map(k => '<div class="mc-rl-kpi"><span class="mc-rl-kpi-rot"><i class="bi ' + esc(k.icone) + '" aria-hidden="true"></i> ' + esc(k.rotulo) + '</span><b class="mc-rl-kpi-v">' + esc(k.valor) + '</b></div>').join('');
    ver.querySelector('[data-mc-rl-html]').srcdoc = c.html || '<p style="font:14px Arial;color:#5b7383;padding:20px">Prévia não disponível para campanhas antigas.</p>';
    const dest = c.destinatarios || [];
    let corpo = dest.map(p => '<div class="mc-rl-dest"><i class="bi ' + (p.lido ? 'bi-check2-all mc-rl-dest-lido' : 'bi-check2') + '" aria-hidden="true"></i><span class="mc-rl-dest-email">' + esc(p.email) + '</span><span class="mc-rl-dest-st">' + esc(p.estado) + '</span></div>').join('');
    if (!dest.length) corpo = '<div class="mc-rl-dest-vazio">' + (c.iniciada ? 'Lista não registrada nesta campanha.' : 'A lista é montada quando o envio começar.') + '</div>';
    if (c.mais > 0) corpo += '<div class="mc-rl-dest-mais">+ ' + esc(c.mais) + ' destinatário(s)</div>';
    ver.querySelector('[data-mc-rl-dest]').innerHTML = corpo;
  }

  function abrir(id) {
    return pedir(url(raiz.dataset.urlCampanha, id))
      .then(c => { preencher(c); ver.hidden = false; ver.querySelector('.mc-rl-ver-voltar').focus(); })
      .catch(err => avisar(err.message));
  }

  function fechar() {
    ver.hidden = true;
    ver.querySelector('[data-mc-rl-html]').srcdoc = '';
    estado.aberta = null;
  }

  function duplicar() {
    const c = estado.aberta;
    if (!c) return;
    const detalhe = { assunto: String(c.assunto || '').replace(/^\[TESTE\]\s*/, ''), titulo: c.titulo || c.assunto || '', mensagem: 'Campanha duplicada: ajuste e envie de novo.' };
    const tratado = !document.dispatchEvent(new CustomEvent('mc:duplicar-campanha', { cancelable: true, detail: detalhe }));
    fechar();
    if (tratado) return;
    let primeiro = null;
    ['assunto', 'titulo'].forEach(campo => {
      const entrada = document.querySelector('[data-mc-cp-campo="' + campo + '"]');
      if (!entrada) return;
      entrada.value = detalhe[campo];
      entrada.dispatchEvent(new Event('input', { bubbles: true }));
      primeiro = primeiro || entrada;
    });
    if (!primeiro) { avisar('Não foi possível abrir o editor da campanha.'); return; }
    primeiro.scrollIntoView({ behavior: 'smooth', block: 'center' });
    primeiro.focus({ preventScroll: true });
    avisar(detalhe.mensagem);
  }

  function cancelar() {
    const c = estado.aberta;
    if (!c || !c.cancelavel) return;
    if (!window.confirm('Cancelar o envio programado desta campanha?')) return;
    pedir(url(raiz.dataset.urlCancelar, c.id), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': raiz.dataset.token || '' }, body: '{}' })
      .then(r => { fechar(); avisar(r.mensagem); carregar(); })
      .catch(err => { fechar(); avisar(err.message); });
  }

  raiz.addEventListener('click', e => {
    const aba = e.target.closest('[data-mc-rl-aba]');
    if (aba) { estado.aba = aba.dataset.mcRlAba; estado.p = 1; estado.sel = {}; carregar(); return; }
    if (e.target.closest('[data-mc-rl-atualizar]')) { carregar(); return; }
    const pagina = e.target.closest('[data-mc-rl-pagina]');
    if (pagina) { estado.p = Math.min(estado.paginas, Math.max(1, estado.p + parseInt(pagina.dataset.mcRlPagina, 10))); estado.sel = {}; carregar(); return; }
    if (e.target.closest('[data-mc-rl-sel-todos]')) {
      const todas = estado.ids.length && estado.ids.every(i => estado.sel[i]);
      estado.sel = {};
      if (!todas) estado.ids.forEach(i => { estado.sel[i] = 1; });
      desenharSelecao();
      return;
    }
    if (e.target.closest('[data-mc-rl-fechar]')) { fechar(); return; }
    if (e.target.closest('[data-mc-rl-duplicar]')) { duplicar(); return; }
    if (e.target.closest('[data-mc-rl-cancelar]')) { cancelar(); return; }
    const l = e.target.closest('[data-mc-rl-id]');
    if (!l) return;
    if (e.target.closest('[data-mc-rl-sel]')) {
      const id = l.dataset.mcRlId;
      if (estado.sel[id]) delete estado.sel[id]; else estado.sel[id] = 1;
      desenharSelecao();
      return;
    }
    abrir(l.dataset.mcRlId);
  });

  raiz.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !ver.hidden) fechar();
  });

  busca.addEventListener('input', () => {
    window.clearTimeout(espera);
    espera = window.setTimeout(() => { estado.q = busca.value.trim(); estado.p = 1; estado.sel = {}; carregar(); }, 300);
  });

  document.addEventListener('mc:campanha-confirmada', carregar);
  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'com' && !carregado) carregar(); });
  }
  if (/[#&]sec=com\b/.test(location.hash)) carregar();
  // F3f (dc L669): depois do "Enviar teste", a aba Enviadas volta com a linha nova ("Teste").
  document.addEventListener('mc:relatorio-recarregar', () => { estado.aba = 'env'; estado.p = 1; carregar(); });
})();
