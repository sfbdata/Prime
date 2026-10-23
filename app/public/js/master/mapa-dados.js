/* BlueJus · dados públicos do mapa (população IBGE + Quadro da Advocacia OAB), menu ⋮ e painel OAB.
   Portado do desenho docs/design/perfis-1.0/mapa-dados.js. Diferenças (spec perfis-master D8):
   - o navegador nunca chama site externo: tudo vem de /master/fontes e /master/ibge/* (o servidor busca, guarda
     o retrato com fonte e data e, se a fonte falhar, mantém o anterior);
   - "Atualizar dados" e o envio de arquivos gravam no servidor (POST com CSRF), não no navegador;
   - arquivos: CSV no formato do modelo baixado pelo menu, ou Excel convertido para esse CSV no navegador (PDF não);
   - atualização diária automática: o mapa pede ao servidor quando o retrato passa de 24 h (uma vez por carga do
     painel; o servidor espera 1 h após falha antes de tentar sozinho de novo). */
(() => {
  const CFG = JSON.parse(document.body.dataset.cfg || '{}');
  const UF = { 11: ['RO', 'Rondônia'], 12: ['AC', 'Acre'], 13: ['AM', 'Amazonas'], 14: ['RR', 'Roraima'], 15: ['PA', 'Pará'], 16: ['AP', 'Amapá'], 17: ['TO', 'Tocantins'], 21: ['MA', 'Maranhão'], 22: ['PI', 'Piauí'], 23: ['CE', 'Ceará'], 24: ['RN', 'Rio Grande do Norte'], 25: ['PB', 'Paraíba'], 26: ['PE', 'Pernambuco'], 27: ['AL', 'Alagoas'], 28: ['SE', 'Sergipe'], 29: ['BA', 'Bahia'], 31: ['MG', 'Minas Gerais'], 32: ['ES', 'Espírito Santo'], 33: ['RJ', 'Rio de Janeiro'], 35: ['SP', 'São Paulo'], 41: ['PR', 'Paraná'], 42: ['SC', 'Santa Catarina'], 43: ['RS', 'Rio Grande do Sul'], 50: ['MS', 'Mato Grosso do Sul'], 51: ['MT', 'Mato Grosso'], 52: ['GO', 'Goiás'], 53: ['DF', 'Distrito Federal'] };
  const porNome = {}; Object.entries(UF).forEach(([c, [s, n]]) => porNome[n] = c);
  const sig2cod = {}; Object.entries(UF).forEach(([c, [s]]) => sig2cod[s] = c);
  let D = { uf: {}, mun: {}, nomes: {}, oab: { data: '', fonte: '', uf: {} } };
  const fmt = n => n >= 1e6 ? (n / 1e6).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mi' : n >= 1e3 ? (n / 1e3).toLocaleString('pt-BR', { maximumFractionDigits: n >= 1e5 ? 0 : 1 }) + ' mil' : String(n);
  const num = n => Number(n).toLocaleString('pt-BR');
  const escH = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const pedir = url => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : Promise.reject(r.status));
  const enviar = (url, campos) => { const f = new FormData(); f.append('_token', CFG.csrf); Object.entries(campos || {}).forEach(([k, v]) => f.append(k, v)); return fetch(url, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(j)))); };
  const oab = () => D.oab;
  const aplicarResumo = r => { D.uf = r.uf || {}; D.anoPop = r.anoPop || ''; D.fonteAdv = r.fonteAdv || ''; D.atualizado = r.atualizado || null; D.oab = r.oab || D.oab; D.urlOab = r.urlOab || ''; };

  let carga = null;
  // Atualização diária sem cron: se o retrato da OAB ou da população passou de 24 h, pede ao servidor em segundo
  // plano (ele trava para não buscar duas vezes e ignora se outro navegador já atualizou) e recarrega ao terminar.
  // No máximo uma vez por carga da página do painel (o iframe recarrega a cada troca de visão): a marca fica na
  // janela do painel; se não der para tocá-la, no sessionStorage do iframe.
  const jaDisparou = () => {
    try { if (window.parent && window.parent !== window) { if (window.parent.__bjFontesAuto) return true; window.parent.__bjFontesAuto = true; return false; } } catch (e) {}
    try { if (sessionStorage.getItem('bj-fontes-auto')) return true; sessionStorage.setItem('bj-fontes-auto', '1'); } catch (e) {}
    return false;
  };
  const atualizarSeVencido = r => { if (r && r.precisaAtualizar && !jaDisparou()) enviar(CFG.atualizar, { automatico: '1' }).then(j => posResumo(j)).catch(() => {}); return r; };
  const popUFs = () => carga || (carga = pedir(CFG.fontes).then(atualizarSeVencido).then(aplicarResumo));
  const pendente = {};
  function popMun(uf) {
    if (D.mun[uf] && Object.keys(D.mun[uf]).length) return Promise.resolve();
    if (pendente[uf]) return pendente[uf];
    pendente[uf] = pedir(CFG.populacao.replace(/XX$/, UF[uf][0])).then(j => { D.mun[uf] = j.valores || {}; Object.assign(D.nomes, j.nomes || {}); D.anoPop = D.anoPop || j.ano; }).finally(() => delete pendente[uf]);
    return pendente[uf];
  }
  window.BJMapaDados = { get: () => D, popUFs, popMun };

  /* informação no tooltip e no painel */
  let alvo = null;
  document.addEventListener('mousemove', e => { const d = e.target && e.target.__data__; alvo = d && d.properties && d.properties.codarea ? String(d.properties.codarea) : null; }, true);
  const extra = c => {
    if (!c) return '';
    if (c.length === 2) { const u = D.uf[c] || {}, p = []; if (u.pop) p.push(fmt(u.pop) + ' hab.'); return p.join(' · ') + (u.adv ? '\nAdvogados: ' + num(u.adv) : ''); }
    const uf = c.slice(0, 2), v = (D.mun[uf] || {})[c]; if (v) return num(v) + ' hab.'; if (UF[uf]) popMun(uf).catch(() => {}); return '';
  };
  const tip = document.getElementById('tip');
  document.addEventListener('mousemove', () => { if (!tip || !alvo) return; const t = tip.textContent; if (!t || t.includes(' hab.') || t.includes('Advogados')) return; const x = extra(alvo); if (x) { const [a, b] = x.split('\n'); tip.textContent = t.replace(' · clique para detalhar', '') + (a ? ' · ' + a : '') + (b ? '\n' + b : ''); } }, false);
  const pNome = document.getElementById('pNome');
  const painelExtra = () => {
    if (!pNome) return; const nome = (pNome.firstChild && pNome.firstChild.nodeType === 3 ? pNome.firstChild.textContent : '').trim(), c = porNome[nome]; let ex = pNome.querySelector('.bjx');
    if (!c) { if (ex) ex.remove(); return; }
    const u = D.uf[c] || {}, users = +((pNome.textContent.match(/(\d+) usuário/) || [])[1] || 0), p = [];
    if (u.pop) p.push(fmt(u.pop) + ' habitantes'); p.push(u.adv ? num(u.adv) + ' advogados' : 'advogados: não informado');
    if (u.adv && users) p.push('alcance ' + (users / u.adv * 100).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '%');
    const tx = p.join(' · ');
    if (!ex) { ex = document.createElement('span'); ex.className = 'bjx'; ex.style.cssText = 'display:block;font-size:11px;font-weight:normal;margin-top:2px;color:#d6ecf7'; pNome.appendChild(ex); }
    if (ex.textContent !== tx) ex.textContent = tx;
  };
  setInterval(painelExtra, 400);

  /* painel OAB */
  let modoOAB = false, abaOAB = 'total', ufOAB = '';
  const css2 = document.createElement('style');
  css2.textContent = '#bjOAB{position:absolute;right:12px;top:46px;width:330px;max-height:calc(100% - 60px);overflow:auto;background:#fff;border:1px solid #dfe7ed;border-radius:4px;box-shadow:0 10px 28px rgba(18,36,47,.16);z-index:8;font-size:12.5px;color:#243845;display:none}#bjOAB.on{display:block}#bjOAB .t{display:flex;align-items:center;gap:8px;padding:9px 12px;background:#0a6fa0;color:#fff;font-weight:bold;position:sticky;top:0}#bjOAB .t button{margin-left:auto;border:none;background:rgba(255,255,255,.16);color:#fff;width:24px;height:24px;border-radius:4px;cursor:pointer}#bjOAB .t button:hover{background:rgba(255,255,255,.3)}#bjOAB .ab{display:flex;gap:2px;margin:8px 10px;background:#eef2f5;border-radius:4px;padding:3px}#bjOAB .ab button{flex:1;height:26px;border:none;border-radius:3px;background:transparent;color:#5b7383;font:bold 11.5px Arial;cursor:pointer}#bjOAB .ab button.on{background:#fff;color:#0a6fa0;box-shadow:0 1px 3px rgba(18,36,47,.12)}#bjOAB select{margin:0 10px 8px;width:calc(100% - 20px);height:28px;border:1px solid #d3dde4;border-radius:3px;font:12.5px Arial;color:#243845}#bjOAB .r{display:flex;justify-content:space-between;gap:8px;padding:6px 12px;border-bottom:1px solid #eef2f5;position:relative}#bjOAB .r b{position:relative}#bjOAB .r span{position:relative}#bjOAB .r i{position:absolute;left:0;top:0;bottom:0;background:#e5f1f7}#bjOAB .g{display:flex;height:8px;margin:2px 12px 8px;border-radius:4px;overflow:hidden}#bjOAB .f{padding:8px 12px;font-size:11px;color:#5b7383;background:#f7f9fb;border-top:1px solid #eef2f5;line-height:1.5}#bjOAB .f a{color:#0a6fa0}#bjLeg{position:absolute;left:12px;bottom:12px;background:#fff;border:1px solid #dfe7ed;border-radius:4px;padding:6px 9px;font-size:11px;color:#4f6878;z-index:5;display:none;box-shadow:0 2px 8px rgba(18,36,47,.08)}';
  document.head.appendChild(css2);
  const pOAB = document.createElement('div'); pOAB.id = 'bjOAB'; const leg = document.createElement('div'); leg.id = 'bjLeg'; document.body.append(pOAB, leg);
  const v0 = x => (x == null ? 0 : x);
  const soma = () => { const t = Array(14).fill(0); Object.values(oab().uf).forEach(v => v.forEach((x, i) => t[i] += v0(x))); return t; };
  function desenharOAB() {
    const v = (ufOAB ? oab().uf[ufOAB] : soma()).map(v0), nome = ufOAB ? (UF[sig2cod[ufOAB]] || [, ufOAB])[1] : 'Brasil', linha = (rot, n, mx, cor) => '<div class="r"><i style="width:' + (mx ? n / mx * 100 : 0) + '%;' + (cor ? 'background:' + cor : '') + '"></i><span>' + rot + '</span><b>' + num(n) + '</b></div>';
    let corpo = '';
    if (abaOAB === 'total') { const tot = v[0] + v[1] + v[2] + v[3], mx = Math.max(v[0], v[1], v[2], v[3]); corpo = linha('Advogados(as)', v[0], mx) + linha('Estagiários(as)', v[1], mx) + linha('Suplementares', v[2], mx) + linha('Consultor estrangeiro', v[3], mx) + '<div class="r" style="font-weight:bold"><span>Total</span><b>' + num(tot) + '</b></div>'; const u = D.uf[sig2cod[ufOAB]] || {}; if (ufOAB && u.pop) corpo += '<div class="r"><span>Advogados por mil habitantes</span><b>' + (v[0] / u.pop * 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '</b></div>'; }
    if (abaOAB === 'genero') { const f = v[4], m = v[5], pf = f + m ? Math.round(f / (f + m) * 100) : 0; corpo = '<div class="g"><span style="width:' + pf + '%;background:#0a6fa0"></span><span style="flex:1;background:#9cc7e2"></span></div>' + linha('Feminino · ' + pf + '%', f, Math.max(f, m), '#d9ecf6') + linha('Masculino · ' + (100 - pf) + '%', m, Math.max(f, m), '#eef4f8'); }
    if (abaOAB === 'idade') { const F = ['Até 25 anos', '26 a 40 anos', '41 a 59 anos', '60 anos ou mais']; const tt = F.map((_, i) => v[6 + i * 2] + v[7 + i * 2]), mx = Math.max(...tt), all = tt.reduce((a, b) => a + b, 0); corpo = F.map((r, i) => '<div class="r"><i style="width:' + (mx ? tt[i] / mx * 100 : 0) + '%"></i><span>' + r + ' <span style="color:#7d909d;font-size:11px">' + (all ? Math.round(tt[i] / all * 100) : 0) + '% · F ' + num(v[6 + i * 2]) + ' · M ' + num(v[7 + i * 2]) + '</span></span><b>' + num(tt[i]) + '</b></div>').join(''); }
    const ops = '<option value="">Brasil (todas as seccionais)</option>' + Object.keys(oab().uf).filter(s => sig2cod[s]).sort((a, b) => UF[sig2cod[a]][1].localeCompare(UF[sig2cod[b]][1])).map(s => '<option value="' + s + '"' + (s === ufOAB ? ' selected' : '') + '>' + UF[sig2cod[s]][1] + '</option>').join('');
    pOAB.innerHTML = '<div class="t">&#9878; Quadro da Advocacia · ' + escH(nome) + '<button title="Fechar">&times;</button></div><div class="ab"><button data-t="total" class="' + (abaOAB === 'total' ? 'on' : '') + '">Quantitativo</button><button data-t="genero" class="' + (abaOAB === 'genero' ? 'on' : '') + '">Gênero</button><button data-t="idade" class="' + (abaOAB === 'idade' ? 'on' : '') + '">Faixa etária</button></div><select>' + ops + '</select>' + corpo
      + '<div class="f">Fonte: Conselho Federal da OAB · regulares e recadastrados · dados de ' + escH(oab().data) + ' (' + escH(oab().fonte) + '). A OAB atualiza diariamente; atualize pelo menu ⋮ ou envie a tabela.<br><a href="' + escH(D.urlOab || 'https://www.oab.org.br/institucionalconselhofederal/quadroadvogados') + '" target="_blank" rel="noopener">Abrir página da OAB</a></div>';
    pOAB.querySelector('.t button').onclick = () => pOAB.classList.remove('on');
    pOAB.querySelectorAll('.ab button').forEach(b => b.onclick = () => { abaOAB = b.dataset.t; desenharOAB(); if (modoOAB) colorir(true); });
    pOAB.querySelector('select').onchange = e => { ufOAB = e.target.value; desenharOAB(); };
  }
  function abrirOAB() { const nome = (pNome && pNome.firstChild && pNome.firstChild.nodeType === 3 ? pNome.firstChild.textContent : '').trim(), c = porNome[nome]; if (c) ufOAB = UF[c][0]; desenharOAB(); pOAB.classList.add('on'); }
  const valorCor = s => { const v = (oab().uf[s] || []).map(v0); if (!v.length) return 0; if (abaOAB === 'genero') return v[4] + v[5] ? v[4] / (v[4] + v[5]) : 0; if (abaOAB === 'idade') { const t = v.slice(6).reduce((a, b) => a + b, 0); return t ? (v[8] + v[9]) / t : 0; } return v[0]; };
  function colorir(on) {
    modoOAB = on; const uZ = document.getElementById('uZoom'); if (!uZ || !window.d3) return;
    const paths = [...uZ.querySelectorAll('path')].filter(p => p.__data__ && p.__data__.properties && String(p.__data__.properties.codarea).length === 2);
    uZ.querySelectorAll('g').forEach(g => { const r = g.querySelector(':scope>rect'); if (r && r.getAttribute('fill') === '#12242f') g.style.display = on ? 'none' : ''; });
    if (!on) { paths.forEach(p => { if (p.__f0 != null) p.setAttribute('fill', p.__f0); }); leg.style.display = 'none'; return; }
    const vals = paths.map(p => valorCor(UF[String(p.__data__.properties.codarea)][0])), mn = Math.min(...vals), mx = Math.max(...vals);
    paths.forEach((p, i) => { if (p.__f0 == null) p.__f0 = p.getAttribute('fill'); p.setAttribute('fill', d3.interpolateRgb('#d6e9f4', '#0a4f7a')(abaOAB === 'total' ? Math.sqrt((vals[i] - mn) / (mx - mn || 1)) : (vals[i] - mn) / (mx - mn || 1))); });
    const fmtv = x => abaOAB === 'total' ? num(Math.round(x)) : Math.round(x * 100) + '%';
    leg.innerHTML = '<b style="color:#0b4f75">' + (abaOAB === 'total' ? 'Advogados OAB' : abaOAB === 'genero' ? '% advogadas' : '% de 26 a 40 anos') + '</b> · ' + fmtv(mn) + ' <span style="display:inline-block;width:70px;height:7px;vertical-align:middle;border-radius:3px;background:linear-gradient(90deg,#d6e9f4,#0a4f7a)"></span> ' + fmtv(mx);
    leg.style.display = 'block';
  }
  document.addEventListener('mousemove', () => { if (!tip || !alvo || !modoOAB || alvo.length !== 2 || !UF[alvo]) return; const s = UF[alvo][0], v = oab().uf[s]; if (!v) return; const t = abaOAB === 'genero' ? Math.round(valorCor(s) * 100) + '% advogadas' : abaOAB === 'idade' ? Math.round(valorCor(s) * 100) + '% de 26 a 40 anos' : num(v0(v[0])) + ' advogados (OAB)'; tip.textContent = UF[alvo][1] + ': ' + t; const w = tip.offsetWidth, l = parseFloat(tip.style.left) || 0; if (l + w > innerWidth - 8) tip.style.left = Math.max(8, innerWidth - w - 8) + 'px'; });
  setInterval(() => { if (modoOAB) { const uZ = document.getElementById('uZoom'); const p = uZ && [...uZ.querySelectorAll('path')].find(x => x.__data__ && x.__data__.properties && String(x.__data__.properties.codarea).length === 2 && x.__f0 == null); if (p) colorir(true); } }, 600);

  /* menu de 3 pontos */
  const css = document.createElement('style');
  css.textContent = '#bjDots{position:absolute;right:12px;top:12px;width:30px;height:30px;border:1px solid #dfe7ed;border-radius:4px;background:#fff;color:#4f6878;font-size:18px;line-height:1;cursor:pointer;z-index:7;box-shadow:0 2px 8px rgba(18,36,47,.08);display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s}#bjDots:hover{background:#e5f1f7;color:#0a6fa0}#bjMenu{position:absolute;right:12px;top:46px;width:270px;background:#fff;border:1px solid #dfe7ed;border-radius:4px;box-shadow:0 10px 28px rgba(18,36,47,.16);z-index:8;font-size:12.5px;color:#243845;display:none;overflow:hidden}#bjMenu.on{display:block}#bjMenu .h{padding:9px 12px;background:#f7f9fb;border-bottom:1px solid #eef2f5;font-size:11px;color:#5b7383;line-height:1.45}#bjMenu button{display:flex;align-items:center;gap:9px;width:100%;padding:9px 12px;border:none;background:#fff;font:inherit;color:#243845;cursor:pointer;text-align:left}#bjMenu button:hover{background:#e5f1f7;color:#0a6fa0}#bjMenu button i{font-style:normal;width:16px;text-align:center;color:#0a6fa0}#bjMenu .sep{height:1px;background:#eef2f5}#bjAviso{position:absolute;right:12px;bottom:12px;background:#e5f1f7;color:#0b4f75;font-size:12px;font-weight:bold;padding:8px 11px;border-radius:4px;box-shadow:0 4px 12px rgba(18,36,47,.15);z-index:9;opacity:0;transition:opacity .25s;pointer-events:none;max-width:300px}#carreg{top:48px!important}';
  document.head.appendChild(css);
  const dots = document.createElement('button'); dots.id = 'bjDots'; dots.title = 'Dados do mapa'; dots.setAttribute('aria-label', 'Dados do mapa'); dots.innerHTML = '&#8942;';
  const menu = document.createElement('div'); menu.id = 'bjMenu';
  const aviso = document.createElement('div'); aviso.id = 'bjAviso';
  const file = document.createElement('input'); file.type = 'file'; file.accept = '.csv,.xlsx,.xls'; file.style.display = 'none';
  const fileOAB = document.createElement('input'); fileOAB.type = 'file'; fileOAB.accept = '.csv,.xlsx,.xls'; fileOAB.style.display = 'none';
  document.body.append(dots, menu, aviso, file, fileOAB);
  const avisar = t => { aviso.textContent = t; aviso.style.opacity = 1; clearTimeout(avisar.t); avisar.t = setTimeout(() => aviso.style.opacity = 0, 5200); };
  const desenhar = () => {
    const at = D.atualizado ? new Date(D.atualizado).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : 'nunca';
    const nAdv = Object.values(D.uf).filter(u => u.adv).length;
    menu.innerHTML = '<div class="h"><b style="color:#0b4f75">Dados públicos</b><br>População: IBGE' + (D.anoPop ? ' (estimativa ' + escH(D.anoPop) + ')' : ': não carregada') + '<br>Advogados: ' + (nAdv ? nAdv + ' de 27 estados informados' : 'não informado') + (D.fonteAdv ? ' · ' + escH(D.fonteAdv) : '') + '<br>Última atualização: ' + escH(at) + '</div>'
      + '<button data-a="oab"><i>&#9878;</i>Quadro da Advocacia (OAB)</button><button data-a="cor"><i>&#9673;</i>' + (modoOAB ? 'Voltar a colorir por usuários' : 'Colorir mapa por advogados') + '</button><div class="sep"></div><button data-a="ibge"><i>&#8635;</i>Atualizar dados (IBGE e OAB)</button><div class="sep"></div><button data-a="baixar"><i>&#8681;</i>Baixar tabela (CSV)</button><button data-a="enviar"><i>&#8679;</i>Enviar tabela atualizada (Excel ou CSV)</button><div class="sep"></div><button data-a="oabcsv"><i>&#8681;</i>Baixar planilha OAB (CSV)</button><button data-a="oabup"><i>&#8679;</i>Atualizar OAB por arquivo (Excel ou CSV)</button>';
    menu.querySelectorAll('button').forEach(b => b.onclick = () => acao(b.dataset.a));
  };
  const baixarCsv = (linhas, nome) => { const blob = new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' }), url = URL.createObjectURL(blob), x = document.createElement('a'); x.href = url; x.download = nome; document.body.appendChild(x); x.click(); x.remove(); setTimeout(() => URL.revokeObjectURL(url), 2000); };
  const posResumo = j => { if (j && j.resumo) aplicarResumo(j.resumo); painelExtra(); if (pOAB.classList.contains('on')) desenharOAB(); if (modoOAB) colorir(true); };
  async function acao(a) {
    menu.classList.remove('on');
    if (a === 'ibge') {
      avisar('Atualizando dados públicos pelo servidor…');
      try { const j = await enviar(CFG.atualizar); posResumo(j); const r = j.resultado || {}; if (r.emAndamento) return avisar('Atualização já em andamento.'); avisar(r.ibge && r.oab ? 'Dados do IBGE e da OAB atualizados.' : (r.ibge ? 'IBGE atualizado. OAB indisponível agora: mantido o retrato de ' + oab().data + '.' : r.oab ? 'OAB atualizada. IBGE indisponível agora: mantida a população anterior.' : 'Não foi possível acessar o IBGE nem a OAB agora. Mantidos os dados anteriores.')); }
      catch (e) { avisar((e && e.erro) || 'Não foi possível atualizar agora. Tente de novo ou envie a tabela.'); }
    }
    if (a === 'oab') abrirOAB();
    if (a === 'cor') colorir(!modoOAB);
    if (a === 'baixar') {
      try { await popUFs(); } catch (e) {}
      const L = ['tipo;codigo_ibge;uf;nome;populacao;advogados'];
      Object.entries(UF).forEach(([c, [s, n]]) => { const u = D.uf[c] || {}; L.push(['estado', c, s, n, u.pop || '', u.adv || ''].join(';')); });
      Object.entries(D.mun).forEach(([uf, o]) => Object.entries(o).forEach(([c, v]) => L.push(['municipio', c, (UF[uf] || [''])[0], (D.nomes[c] || '').replace(/ - [A-Z]{2}$/, '').replace(/;/g, ','), v, ''].join(';'))));
      baixarCsv(L, 'bluejus-mapa-dados.csv');
      avisar('Tabela baixada. Preencha a coluna "advogados" e envie de volta.');
    }
    if (a === 'enviar') file.click();
    if (a === 'oabup') fileOAB.click();
    if (a === 'oabcsv') {
      const o = oab().uf, S = Object.keys(o).sort();
      const L = ['SECCIONAL;Advogado(a);Estagiário(a);Suplementar;Consultor Estrangeiro;Advogados Feminino;Advogados Masculino;Até 25 anos Feminino;Até 25 anos Masculino;26 a 40 anos Feminino;26 a 40 anos Masculino;41 a 59 anos Feminino;41 a 59 anos Masculino;60 anos ou mais Feminino;60 anos ou mais Masculino'];
      S.forEach(s => L.push([s].concat(o[s].map(x => (x == null ? '' : x))).join(';')));
      // Linha TOTAL: o servidor confere a soma de cada coluna ao receber a planilha de volta.
      L.push(['TOTAL'].concat(Array.from({ length: 14 }, (_, i) => (S.every(s => o[s][i] != null) ? S.reduce((a, s) => a + o[s][i], 0) : ''))).join(';'));
      baixarCsv(L, 'quadro-advocacia-oab-' + String(oab().data).replace(/\//g, '-') + '.csv');
      avisar('Planilha OAB baixada (' + oab().data + ').');
    }
  }
  // Excel (.xlsx/.xls) vira, no navegador, o mesmo CSV que o servidor lê: 1ª planilha, separador ";".
  // Biblioteca local (public/js/vendor/xlsx), carregada só quando usada. PDF não é lido.
  const carregarXlsx = () => window.XLSX ? Promise.resolve(window.XLSX) : new Promise((ok, erro) => { const sc = document.createElement('script'); sc.src = CFG.xlsx; sc.onload = () => ok(window.XLSX); sc.onerror = erro; document.head.appendChild(sc); });
  const comoCsv = async f => {
    if (!/\.xlsx?$/i.test(f.name)) return f;
    const X = await carregarXlsx(), wb = X.read(await f.arrayBuffer(), { type: 'array' }), ws = wb.Sheets[wb.SheetNames[0]];
    return new File([X.utils.sheet_to_csv(ws, { FS: ';', blankrows: false, rawNumbers: true })], f.name.replace(/\.xlsx?$/i, '') + '.csv', { type: 'text/csv' });
  };
  file.onchange = async () => {
    const f = file.files[0]; file.value = ''; if (!f) return;
    try { const j = await enviar(CFG.tabela, { arquivo: await comoCsv(f) }); posResumo(j); D.mun = {}; avisar('Tabela importada: ' + j.resultado.estados + ' estado(s) e ' + j.resultado.municipios + ' município(s).'); }
    catch (e) { avisar((e && e.erro) || 'Tabela não reconhecida. Use o modelo baixado pelo menu.'); }
  };
  fileOAB.onchange = async () => {
    const f = fileOAB.files[0]; fileOAB.value = ''; if (!f) return; avisar('Lendo ' + f.name + '…');
    try { const j = await enviar(CFG.oab, { arquivo: await comoCsv(f) }); posResumo(j); avisar('OAB atualizada (' + j.resultado.data + '): ' + j.resultado.seccionais + ' seccionais. Colunas vazias mantidas da versão anterior.'); }
    catch (e) { avisar((e && e.erro) || 'Não foi possível ler o arquivo. Use o CSV no formato do modelo baixado pelo menu.'); }
  };
  dots.onclick = e => { e.stopPropagation(); desenhar(); menu.classList.toggle('on'); };
  document.addEventListener('click', e => { if (!menu.contains(e.target) && e.target !== dots) menu.classList.remove('on'); });
  addEventListener('keydown', e => { if (e.key === 'Escape') menu.classList.remove('on'); });
  popUFs().catch(() => {});
})();
