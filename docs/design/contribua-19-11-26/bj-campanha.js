/* BlueJus · Modo campanha "BlueJus Black" (Desafio R$ 1 com devolução em dobro ou triplo)
   Uma única fonte de verdade: a configuração do Master gera a aba do topo, a página da campanha,
   as regras e os textos da tela Contribua. Nada é escrito à mão em dois lugares, para não haver divergência.
   Protótipo: localStorage 'bj-campanha' (config), 'bj-campanha-part' (minha participação), 'bj-campanha-ped' (pedidos).
   Sistema real: pagamento por Pix com confirmação do banco (webhook), 1 participação por CPF e por inscrição na OAB,
   devolução por Pix registrada com comprovante, trilha de auditoria. Ver ESPEC_CAMPANHA.md. */
(function () {
  if (window.BJCampanha) return;
  const KC = 'bj-campanha', KP = 'bj-campanha-part', KD = 'bj-campanha-ped';
  const PADRAO = {
    on: true, nome: 'BlueJus Black', chamada: 'Black Friday BlueJus', preco: 1, mult: 2, prazoPedido: 30, prazoPagto: 7,
    inicio: '2026-11-19', fim: '2026-11-30', vagas: 0, contador: false, aba: true,
    beneficios: ['Implantação assistida: importamos suas pastas, clientes e prazos', 'Suporte prioritário pelo WhatsApp durante a campanha', 'Treinamento ao vivo para a sua equipe', 'Selo "Fundador BlueJus Black" no seu perfil']
  };
  const ler = (k, d) => { try { const v = JSON.parse(localStorage.getItem(k)); return v == null ? d : v; } catch (e) { return d; } };
  const cfg = () => Object.assign({}, PADRAO, ler(KC, {}));
  const ev = () => { try { dispatchEvent(new CustomEvent('bj-campanha')); } catch (e) {} };
  const salvar = c => { localStorage.setItem(KC, JSON.stringify(Object.assign(cfg(), c))); ev(); render(); };
  const brl = v => 'R$ ' + Number(v).toFixed(2).replace('.', ',');
  const dt = s => s ? s.split('-').reverse().join('/') : '';
  const hoje = () => new Date().toISOString().slice(0, 10);
  const master = () => { const R = window.BJRecursos; return R && R.master ? R.master() : true; };
  const pedidos = () => ler(KD, []);
  const vagasUsadas = () => pedidos().length + (ler(KP, null) ? 1 : 0);
  function estado() { const c = cfg(); if (!c.on) return 'desligada'; const h = hoje(); if (h < c.inicio) return 'agendada'; if (h > c.fim) return 'encerrada'; if (c.vagas && vagasUsadas() >= c.vagas) return 'esgotada'; return 'ativa'; }
  const multTx = m => m === 3 ? 'o triplo' : 'o dobro';
  const devolve = c => brl(c.preco * c.mult);
  /* Regras geradas da configuração: as mesmas frases aparecem na página da campanha e na tela Contribua. */
  function regras() {
    const c = cfg();
    return [
      'Valor único de ' + brl(c.preco) + ', pago por Pix. Sem cartão, sem renovação automática e sem cobrança posterior.',
      'O BlueJus continua gratuito para todos. O ' + brl(c.preco) + ' é só a inscrição no Desafio e dá acesso aos benefícios da campanha listados nesta página.',
      'Espaço de armazenamento não faz parte da campanha: continua na modalidade atual, gratuito até o limite e ampliado por liberação.',
      'Garantia do Desafio: se não gostar, peça a devolução em até ' + c.prazoPedido + ' dias da inscrição e receba ' + devolve(c) + ' (' + multTx(c.mult) + ' do valor pago) por Pix em até ' + c.prazoPagto + ' dias úteis. Não precisa justificar.',
      'O direito de arrependimento de 7 dias do Código de Defesa do Consumidor (art. 49) continua valendo, independentemente do Desafio.',
      'Uma inscrição por CPF e por inscrição na OAB.' + (c.vagas ? ' Limite de ' + c.vagas + ' vagas.' : ''),
      'Período: de ' + dt(c.inicio) + ' a ' + dt(c.fim) + '. Pedidos de devolução feitos dentro do prazo são honrados mesmo depois do fim da campanha.',
      'Não é sorteio nem concurso: todo participante que pedir a devolução no prazo recebe.',
      'Ao pedir a devolução, os benefícios da campanha se encerram. Suas pastas e seus dados continuam no BlueJus gratuito; nada é apagado.',
      'Os dados informados (nome, CPF, OAB e chave Pix) são usados só para a inscrição e a devolução, conforme a Política de Privacidade.'
    ];
  }
  const part = () => ler(KP, null);
  const diasDesde = t => Math.floor((Date.now() - t) / 864e5);
  function podeDevolver() { const p = part(), c = cfg(); return !!(p && p.st === 'ativo' && diasDesde(p.em) <= c.prazoPedido); }
  function aderir(o) { const c = cfg(); if (estado() !== 'ativa') return { ok: false, erro: 'A campanha não está aberta para inscrições.' }; if (part()) return { ok: false, erro: 'Você já está no Desafio.' };
    const cpf = String(o.cpf || '').replace(/\D/g, ''); if (cpf.length !== 11) return { ok: false, erro: 'Informe um CPF com 11 dígitos.' }; if (!String(o.oab || '').trim()) return { ok: false, erro: 'Informe sua inscrição na OAB.' };
    const p = { id: 'cp-' + Date.now().toString(36), nome: o.nome || '', cpfFim: cpf.slice(-2), oab: String(o.oab).trim().toUpperCase(), valor: c.preco, mult: c.mult, em: Date.now(), st: 'ativo', simulado: true };
    localStorage.setItem(KP, JSON.stringify(p)); ev(); return { ok: true, p }; }
  function pedirDevolucao(o) { const c = cfg(), p = part(); if (!podeDevolver()) return { ok: false, erro: 'Fora do prazo ou sem inscrição ativa.' }; if (!String(o.pix || '').trim()) return { ok: false, erro: 'Informe a chave Pix para receber.' };
    p.st = 'pedida'; p.pedidoEm = Date.now(); p.valorDev = c.preco * p.mult; p.motivo = String(o.motivo || '').slice(0, 400); localStorage.setItem(KP, JSON.stringify(p));
    const L = pedidos(); L.unshift({ id: p.id, nome: p.nome || 'Participante', oab: p.oab, valor: p.valorDev, motivo: p.motivo, pix: String(o.pix).trim(), em: p.pedidoEm, st: 'pendente' }); localStorage.setItem(KD, JSON.stringify(L)); ev(); return { ok: true, p }; }
  function marcarPago(id) { const L = pedidos(), r = L.find(x => x.id === id); if (!r) return; r.st = 'devolvido'; r.pagoEm = Date.now(); localStorage.setItem(KD, JSON.stringify(L)); const p = part(); if (p && p.id === id) { p.st = 'devolvido'; localStorage.setItem(KP, JSON.stringify(p)); } ev(); }
  function zerarDemo() { localStorage.removeItem(KP); localStorage.removeItem(KD); ev(); render(); }

  /* ---------- Interface ---------- */
  const esc = t => String(t == null ? '' : t).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const ARIAL = 'font-family:Arial,Helvetica,sans-serif;';
  function cabecalho() { const xs = Array.from(document.querySelectorAll('header, [role=banner]')).filter(h => { const r = h.getBoundingClientRect(); return r.top <= 2 && r.height >= 40 && r.height <= 140 && r.width >= innerWidth * .8; }); return xs[0] ? xs[0].getBoundingClientRect().bottom : 0; }
  function aba() {
    let a = document.getElementById('bjc-aba'); const c = cfg(), st = estado(), m = master();
    const mostrar = c.aba && (st === 'ativa' || st === 'esgotada' || (m && st !== 'ativa')) && !document.getElementById('bje-ov');
    if (!mostrar || !document.body) { if (a) a.remove(); return; }
    if (!a) { a = document.createElement('button'); a.id = 'bjc-aba'; a.type = 'button'; a.setAttribute('data-print', 'hide'); a.onclick = () => abrir(); document.body.appendChild(a); }
    const prev = st !== 'ativa' && st !== 'esgotada';
    a.title = prev ? 'Prévia do Master: campanha ' + st + ' (só você vê)' : c.chamada + ': Desafio ' + brl(c.preco);
    a.style.cssText = ARIAL + 'position:fixed;z-index:2147483000;right:24px;top:' + cabecalho() + 'px;display:flex;align-items:center;gap:8px;height:34px;padding:0 14px;border:' + (prev ? '2px dashed #f0b429' : '0') + ';border-top:0;border-radius:0 0 4px 4px;background:#0d0d0d;color:#fff;font-size:13px;font-weight:bold;letter-spacing:.5px;cursor:pointer;box-shadow:0 6px 16px rgba(0,0,0,.28);';
    a.innerHTML = '<span style="display:inline-flex;align-items:center;justify-content:center;height:20px;padding:0 6px;border-radius:3px;background:#3aa0d8;color:#0d0d0d;font-size:11px;">' + esc(c.chamada.split(' ').slice(0, 2).join(' ').toUpperCase()) + '</span>' + esc(c.nome) + '<span style="color:#7fd0ff;">' + brl(c.preco) + '</span>' + (prev ? '<span style="font-size:11px;color:#f0b429;">PRÉVIA</span>' : '');
  }
  function fechar() { const o = document.getElementById('bjc-ov'); if (o) o.remove(); document.removeEventListener('keydown', escK); }
  const escK = e => { if (e.key === 'Escape') fechar(); };
  function abrir(foco) {
    fechar(); const c = cfg(), st = estado(), p = part(), m = master();
    const o = document.createElement('div'); o.id = 'bjc-ov'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); o.setAttribute('aria-label', c.nome);
    o.style.cssText = ARIAL + 'position:fixed;inset:0;z-index:2147483300;background:rgba(5,10,15,.62);display:flex;align-items:flex-start;justify-content:center;padding:18px 12px;overflow:auto;';
    const B = (t, cor, bg, id, extra) => '<button data-a="' + id + '" style="height:40px;padding:0 18px;border:' + (extra || '0') + ';border-radius:4px;background:' + bg + ';color:' + cor + ';font:bold 14px Arial;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">' + t + '</button>';
    const stTx = { desligada: 'Campanha desligada', agendada: 'Começa em ' + dt(c.inicio), encerrada: 'Campanha encerrada em ' + dt(c.fim), esgotada: 'Vagas esgotadas', ativa: 'Inscrições abertas até ' + dt(c.fim) }[st];
    let acao = '';
    if (p && p.st === 'ativo') { const resta = c.prazoPedido - diasDesde(p.em); acao = '<div style="background:#12324a;border:1px solid #2b6a92;border-radius:4px;padding:14px;display:flex;flex-direction:column;gap:10px;"><b style="font-size:15px;color:#fff;">Você está no Desafio desde ' + new Date(p.em).toLocaleDateString('pt-BR') + '</b><span style="color:#d7e6ef;font-size:13.5px;">Use o BlueJus no dia a dia. Se não gostar, você ainda tem ' + Math.max(0, resta) + ' dia(s) para pedir ' + devolve(Object.assign({}, c, { mult: p.mult })) + ' de volta.</span>' + (podeDevolver() ? '<div>' + B('Não gostei: quero ' + devolve(Object.assign({}, c, { mult: p.mult })) + ' de volta', '#fff', 'transparent', 'devForm', '1px solid #9fc3d8') + '</div><div id="bjc-dev" style="display:none;flex-direction:column;gap:8px;"><label style="color:#d7e6ef;font-size:12.5px;">Chave Pix para receber *<input id="bjc-pix" style="display:block;width:100%;box-sizing:border-box;height:38px;margin-top:4px;border:1px solid #7b93a2;border-radius:4px;padding:0 10px;font:14px Arial;"></label><label style="color:#d7e6ef;font-size:12.5px;">O que faltou? (opcional, nos ajuda a melhorar)<textarea id="bjc-mot" rows="2" style="display:block;width:100%;box-sizing:border-box;margin-top:4px;border:1px solid #7b93a2;border-radius:4px;padding:8px 10px;font:14px Arial;"></textarea></label><div>' + B('Confirmar pedido de devolução', '#0d0d0d', '#7fd0ff', 'devOk') + '</div></div>' : '') + '</div>'; }
    else if (p && p.st === 'pedida') acao = '<div style="background:#3a2a06;border:1px solid #f0b429;border-radius:4px;padding:14px;color:#ffe7ad;font-size:13.5px;"><b style="color:#fff;">Devolução pedida em ' + new Date(p.pedidoEm).toLocaleDateString('pt-BR') + '.</b> Você recebe ' + brl(p.valorDev) + ' por Pix em até ' + c.prazoPagto + ' dias úteis. Obrigado por testar; suas pastas continuam no BlueJus gratuito.</div>';
    else if (p && p.st === 'devolvido') acao = '<div style="background:#123a24;border:1px solid #2f9e62;border-radius:4px;padding:14px;color:#d4f2e1;font-size:13.5px;"><b style="color:#fff;">Devolução de ' + brl(p.valorDev) + ' concluída.</b> Obrigado por participar do Desafio.</div>';
    else if (st === 'ativa') acao = '<div style="background:#12324a;border:1px solid #2b6a92;border-radius:4px;padding:14px;display:flex;flex-direction:column;gap:10px;"><b style="color:#fff;font-size:15px;">Entrar no Desafio</b><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;"><label style="color:#d7e6ef;font-size:12.5px;">Nome<input id="bjc-nome" style="display:block;width:100%;box-sizing:border-box;height:38px;margin-top:4px;border:1px solid #7b93a2;border-radius:4px;padding:0 10px;font:14px Arial;"></label><label style="color:#d7e6ef;font-size:12.5px;">CPF *<input id="bjc-cpf" inputmode="numeric" placeholder="somente números" style="display:block;width:100%;box-sizing:border-box;height:38px;margin-top:4px;border:1px solid #7b93a2;border-radius:4px;padding:0 10px;font:14px Arial;"></label><label style="color:#d7e6ef;font-size:12.5px;">OAB *<input id="bjc-oab" placeholder="ex.: 12345/DF" style="display:block;width:100%;box-sizing:border-box;height:38px;margin-top:4px;border:1px solid #7b93a2;border-radius:4px;padding:0 10px;font:14px Arial;"></label></div><label style="display:flex;gap:8px;align-items:flex-start;color:#d7e6ef;font-size:12.5px;cursor:pointer;"><input id="bjc-ok" type="checkbox" style="width:16px;height:16px;margin-top:1px;">Li e aceito as regras do Desafio abaixo.</label><div>' + B('Pagar ' + brl(c.preco) + ' com Pix e entrar', '#0d0d0d', '#7fd0ff', 'aderir') + '</div><div id="bjc-pixbox" style="display:none;"></div></div>';
    else acao = '<div style="background:#1d1d1d;border:1px solid #444;border-radius:4px;padding:14px;color:#cfd8de;font-size:13.5px;">' + esc(stTx) + '.</div>';
    o.innerHTML = '<div style="width:min(760px,100%);background:#0d0d0d;color:#e9eef2;border-radius:4px;box-shadow:0 30px 70px rgba(0,0,0,.5);overflow:hidden;">' +
      '<div style="padding:22px clamp(14px,4vw,28px) 18px;background:linear-gradient(135deg,#0d0d0d 0%,#0d0d0d 55%,#0b3550 100%);border-bottom:3px solid #3aa0d8;position:relative;">' +
      '<button data-a="fechar" aria-label="Fechar" style="position:absolute;top:12px;right:12px;width:34px;height:34px;border:1px solid #444;border-radius:4px;background:transparent;color:#fff;font-size:18px;cursor:pointer;">×</button>' +
      '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;"><span style="height:24px;padding:0 9px;border-radius:3px;background:#3aa0d8;color:#0d0d0d;font-size:12px;font-weight:bold;display:inline-flex;align-items:center;letter-spacing:.6px;">' + esc(c.chamada.toUpperCase()) + '</span><span style="font-size:12.5px;color:#9fc3d8;font-weight:bold;">' + esc(stTx) + '</span>' + (c.contador && st !== 'desligada' ? '<span style="font-size:12.5px;color:#9fc3d8;">· ' + vagasUsadas() + ' inscrito(s)' + (c.vagas ? ' de ' + c.vagas : '') + '</span>' : '') + '</div>' +
      '<div style="font-size:clamp(26px,5vw,38px);font-weight:bold;line-height:1.1;color:#fff;">' + esc(c.nome) + '</div>' +
      '<div style="font-size:clamp(17px,3vw,22px);font-weight:bold;margin-top:8px;color:#fff;">Desafio de <span style="color:#7fd0ff;">' + brl(c.preco) + '</span>. Não gostou? Devolvemos <span style="color:#7fd0ff;">' + devolve(c) + '</span>.</div>' +
      '<div style="font-size:14px;color:#c9d6de;margin-top:8px;line-height:1.55;max-width:600px;">O BlueJus é gratuito. O Desafio é um convite para usar de verdade, com acompanhamento da nossa equipe. Se não aprovar, você recebe ' + multTx(c.mult) + ' do que pagou. É simples assim: confiamos no sistema.</div></div>' +
      '<div style="padding:18px clamp(14px,4vw,28px) 22px;display:flex;flex-direction:column;gap:16px;">' +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;">' + [['1', 'Inscreva-se com ' + brl(c.preco) + ' no Pix'], ['2', 'Use o BlueJus no seu escritório, com nossa ajuda'], ['3', 'Não gostou? Peça ' + devolve(c) + ' em até ' + c.prazoPedido + ' dias']].map(x => '<div style="display:flex;gap:10px;align-items:center;padding:12px;border:1px solid #2a2a2a;border-radius:4px;background:#151515;"><b style="flex:none;width:28px;height:28px;border-radius:4px;background:#3aa0d8;color:#0d0d0d;display:flex;align-items:center;justify-content:center;">' + x[0] + '</b><span style="font-size:13.5px;color:#fff;">' + esc(x[1]) + '</span></div>').join('') + '</div>' +
      acao +
      '<div><div style="font-size:11.5px;font-weight:bold;letter-spacing:1px;color:#9fc3d8;margin-bottom:8px;">O QUE O DESAFIO INCLUI</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px;">' + c.beneficios.filter(Boolean).map(b => '<div style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;color:#e9eef2;line-height:1.45;"><span style="color:#7fd0ff;font-weight:bold;">✓</span><span>' + esc(b) + '</span></div>').join('') + '<div style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;color:#e9eef2;line-height:1.45;"><span style="color:#7fd0ff;font-weight:bold;">✓</span><span>Todo o sistema, que já é gratuito, continua liberado</span></div></div></div>' +
      '<details open style="border:1px solid #2a2a2a;border-radius:4px;background:#151515;"><summary style="cursor:pointer;padding:11px 14px;font-weight:bold;font-size:14px;color:#fff;">Regras do Desafio</summary><ol style="margin:0;padding:0 18px 14px 34px;display:flex;flex-direction:column;gap:6px;font-size:13px;color:#d7e0e6;line-height:1.5;">' + regras().map(r => '<li>' + esc(r) + '</li>').join('') + '</ol></details>' +
      '<div style="font-size:12px;color:#9aa9b3;line-height:1.5;">' + esc(c.nome) + ' é uma campanha da BlueJus Gestão e Tecnologia Ltda. ' + esc(c.chamada) + ' é o nome da ação comercial desta temporada. Dúvidas: bluejus.86@gmail.com.</div>' +
      (m ? '<div style="display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid #2a2a2a;padding-top:12px;"><a href="./08 - Contribua -  VS 03.11.26.dc.html#campanha" style="height:34px;padding:0 12px;border:1px solid #f0b429;border-radius:4px;color:#f0b429;font-size:13px;font-weight:bold;text-decoration:none;display:inline-flex;align-items:center;">Configurar campanha (Master)</a>' + (p ? '<button data-a="zerar" style="height:34px;padding:0 12px;border:1px solid #555;border-radius:4px;background:transparent;color:#cfd8de;font:13px Arial;cursor:pointer;">Zerar participação de teste</button>' : '') + '</div>' : '') +
      '</div></div>';
    o.addEventListener('click', e => { if (e.target === o) return fechar(); const b = e.target.closest('[data-a]'); if (!b) return; const a = b.getAttribute('data-a');
      if (a === 'fechar') fechar();
      if (a === 'zerar') { zerarDemo(); abrir(); }
      if (a === 'devForm') { const d = o.querySelector('#bjc-dev'); d.style.display = 'flex'; b.style.display = 'none'; o.querySelector('#bjc-pix').focus(); }
      if (a === 'devOk') { const r = pedirDevolucao({ pix: o.querySelector('#bjc-pix').value, motivo: o.querySelector('#bjc-mot').value }); if (!r.ok) return alerta(o, r.erro); abrir(); }
      if (a === 'aderir') { if (!o.querySelector('#bjc-ok').checked) return alerta(o, 'Para entrar, marque que leu e aceita as regras.'); const box = o.querySelector('#bjc-pixbox'); box.style.display = 'block';
        box.innerHTML = '<div style="margin-top:6px;padding:12px;border:1px dashed #f0b429;border-radius:4px;color:#ffe7ad;font-size:13px;line-height:1.5;"><b>Protótipo:</b> no sistema real, aqui aparece o QR Code Pix de ' + brl(c.preco) + ' gerado pelo banco, e a inscrição só é confirmada quando o banco confirma o pagamento. Nenhuma cobrança foi feita.<div style="margin-top:8px;"><button data-a="simPago" style="height:34px;padding:0 12px;border:1px solid #f0b429;border-radius:4px;background:transparent;color:#f0b429;font:bold 13px Arial;cursor:pointer;">Simular pagamento confirmado (teste)</button></div></div>'; }
      if (a === 'simPago') { const r = aderir({ nome: o.querySelector('#bjc-nome').value, cpf: o.querySelector('#bjc-cpf').value, oab: o.querySelector('#bjc-oab').value }); if (!r.ok) return alerta(o, r.erro); abrir(); }
    });
    document.body.appendChild(o); document.addEventListener('keydown', escK);
  }
  function alerta(o, t) { let a = o.querySelector('#bjc-al'); if (!a) { a = document.createElement('div'); a.id = 'bjc-al'; a.setAttribute('role', 'alert'); a.style.cssText = ARIAL + 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#a3232b;color:#fff;padding:10px 16px;border-radius:4px;font-size:13.5px;font-weight:bold;z-index:2147483400;'; o.appendChild(a); } a.textContent = t; clearTimeout(a._t); a._t = setTimeout(() => a.remove(), 3500); }
  function render() { aba(); }
  window.BJCampanha = { PADRAO, cfg, salvar, estado, regras, part, pedidos, aderir, pedirDevolucao, marcarPago, zerarDemo, abrir, brl, dt, multTx, devolve: () => devolve(cfg()), podeDevolver };
  const ini = () => { render(); setTimeout(render, 600); addEventListener('resize', render); addEventListener('hashchange', () => setTimeout(render, 50)); if (/[#&]black\b/.test(location.hash)) abrir(); };
  if (document.body) ini(); else addEventListener('DOMContentLoaded', ini);
  addEventListener('storage', e => { if (e.key === KC || e.key === KP || e.key === KD) { render(); ev(); } });
})();
