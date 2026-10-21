/* BlueJus · Contas (multiconta) — carregar ANTES de bluejus-central.js
   Regra: quem "Abre conta" vira o USUÁRIO PRIMÁRIO daquela conta (gestor do escritório).
          Convidados são sempre SECUNDÁRIOS. Só o primário convida, remove ou altera cargo.
          Cada conta é isolada: tudo que o sistema grava fica no espaço da própria conta.
          O MASTER (Farlei Assis da Rocha) é único na plataforma e não pode ser criado aqui.
   Sistema real: contas = tabela tenants; papel por usuário em tenant_users (primario|secundario);
                 isolamento por tenant_id em toda tabela (row-level security). Aqui é simulado no navegador. */
(function () {
  if (window.BJContas) return;
  const REG = 'bj-contas-v1', DEMO = 'conta-farlei';
  const MASTER_EMAIL = 'farlei.rocha@gmail.com';
  const PROIB = /(master|super\s*-?\s*admin|administrador\s+(principal|geral|master)|propriet[aá]ri[oa]\s+do\s+sistema)/i;
  const LS = window.localStorage, P = Storage.prototype, raw = { get: P.getItem, set: P.setItem, rem: P.removeItem };
  const ler = () => { try { return JSON.parse(raw.get.call(LS, REG) || 'null'); } catch (e) { return null; } };
  const gravar = r => raw.set.call(LS, REG, JSON.stringify(r));
  const slug = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40);
  const uid = p => p + '-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

  let R = ler();
  if (!R) {
    R = { ativa: DEMO, sessao: {}, contas: { [DEMO]: { id: DEMO, escritorio: 'Farlei Assis da Rocha Sociedade de Advogados', primario: 'farlei', criada: Date.now(), demo: true,
      usuarios: [['farlei', 'Farlei Assis da Rocha', MASTER_EMAIL, 'Advogado'], ['samuel', 'Samuel Freitas', 'samuel@bluejus.com.br', 'Advogado'], ['jessica', 'Jéssica Martins', 'jessica@bluejus.com.br', 'Advogada'], ['mariana', 'Mariana Costa', 'mariana@bluejus.com.br', 'Coordenadora'], ['rafael', 'Rafael Nunes', 'rafael@bluejus.com.br', 'Advogado'], ['thamara', 'Thamara Lopes', 'thamara@bluejus.com.br', 'Assistente'], ['rodrigo', 'Rodrigo Alves', 'rodrigo@bluejus.com.br', 'Estagiário'], ['helena', 'Helena Prado', 'helena@bluejus.com.br', 'Advogada']]
        .map(([id, nome, email, funcao]) => ({ id, nome, email, funcao, setor: '', papel: id === 'farlei' ? 'primario' : 'secundario', desde: Date.now() })) } }, log: [] };
    gravar(R);
  }
  const conta = () => R.contas[R.ativa] || R.contas[DEMO];
  const auditar = (acao, res) => { R.log = (R.log || []).concat({ em: Date.now(), conta: R.ativa, por: (atual() || {}).id || '', acao, res }).slice(-400); gravar(R); };
  const COMO = (() => { try { return new URLSearchParams(location.search).get('como') || sessionStorage.getItem('bj-como'); } catch (e) { return null; } })();
  function atual() { const c = conta(), s = (R.sessao || {})[c.id] || (COMO ? null : c.primario); return c.usuarios.find(u => u.id === (COMO && !(R.sessao || {})[c.id] ? COMO : s)) || null; }

  /* ---------- Isolamento: cada conta grava num espaço próprio (a conta demo mantém as chaves originais) ---------- */
  const pref = k => { const c = conta(); return (c.demo || k === REG) ? k : 'bjt:' + c.id + ':' + k; };
  P.getItem = function (k) { return raw.get.call(this, this === LS ? pref(k) : k); };
  P.setItem = function (k, v) { return raw.set.call(this, this === LS ? pref(k) : k, v); };
  P.removeItem = function (k) { return raw.rem.call(this, this === LS ? pref(k) : k); };

  /* ---------- Contexto para o restante do sistema ---------- */
  (function contexto() {
    const c = conta(), u = atual();
    window.BLUEJUS_CONTA = { id: c.id, escritorio: c.escritorio, primario: c.primario };
    if (!c.demo) window.BLUEJUS_USUARIOS = c.usuarios.map(x => ({ id: x.id, nome: x.nome, email: x.email, setor: x.setor || '', funcao: x.funcao || '' }));
    if (u) window.BLUEJUS_USUARIO_ATUAL = { id: u.id, nome: u.nome, email: u.email, setor: u.setor || '', funcao: u.funcao || '' };
    else if (!c.demo) { const p = c.usuarios.find(x => x.id === c.primario); if (p) window.BLUEJUS_USUARIO_ATUAL = { id: p.id, nome: p.nome, email: p.email, setor: '', funcao: p.funcao || '' }; }
  })();

  const souPrimario = () => { const c = conta(), u = atual() || (window.BLUEJUS_USUARIO_ATUAL || {}); return u.id === c.primario || (u.email || '').toLowerCase() === MASTER_EMAIL; };
  const recarregar = () => setTimeout(() => location.reload(), 700);

  function abrirConta(o) {
    const esc = String(o.escritorio || '').trim(), nome = String(o.nome || '').trim(), email = String(o.email || '').trim().toLowerCase();
    if (!esc || !nome || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return { ok: false, motivo: 'Preencha escritório, seu nome e um e-mail válido.' };
    if (PROIB.test(esc) || PROIB.test(nome)) { auditar('abrir conta com nome reservado', 'bloqueado'); return { ok: false, motivo: 'Esse nome é reservado pelo sistema.' }; }
    const id = 'conta-' + slug(esc) + '-' + Math.random().toString(36).slice(2, 6), u = { id: email === MASTER_EMAIL ? 'farlei' : uid('u'), nome, email, funcao: o.funcao || 'Advogado', setor: '', oab: o.oab || '', papel: 'primario', desde: Date.now() };
    R.contas[id] = { id, escritorio: esc, primario: u.id, criada: Date.now(), usuarios: [u], plano: 'Free', limiteGB: 10, usoGB: 0 };
    R.ativa = id; R.sessao = Object.assign({}, R.sessao, { [id]: u.id }); auditar('conta aberta por ' + nome + ' (primário)', 'ok'); try { const A = JSON.parse(LS.getItem('bj-com-auto') || '{}'); if (!A.cad || A.cad.on !== false) { const F = JSON.parse(LS.getItem('bj-com-fila') || '[]'); F.unshift({ gat: 'Conta aberta', assunto: 'Confirme seu e-mail para ativar o BlueJus', para: u.email, em: Date.now() }); LS.setItem('bj-com-fila', JSON.stringify(F.slice(0, 50))); } } catch (e) {}
    return { ok: true, conta: id };
  }
  function convidar(o) {
    if (!souPrimario()) { auditar('tentativa de convite por secundário', 'bloqueado'); return { ok: false, motivo: 'Somente o usuário primário pode convidar pessoas para a conta.' }; }
    const c = conta(), nome = String(o.nome || '').trim(), email = String(o.email || '').trim().toLowerCase();
    if (!nome || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return { ok: false, motivo: 'Informe nome e e-mail válido.' };
    if (PROIB.test(nome) || PROIB.test(o.funcao || '') || /prim[aá]ri/i.test(o.funcao || '')) { auditar('convite com cargo reservado', 'bloqueado'); return { ok: false, motivo: 'Convidados são sempre secundários. Esse cargo não pode ser usado.' }; }
    if (c.usuarios.some(u => u.email === email)) return { ok: false, motivo: 'Esse e-mail já faz parte da conta.' };
    const u = { id: email === MASTER_EMAIL ? 'farlei' : uid('u'), nome, email, funcao: o.funcao || 'Assistente', setor: '', papel: 'secundario', desde: Date.now(), convidadoPor: (atual() || {}).id };
    c.usuarios.push(u); auditar('convidou ' + nome + ' (secundário)', 'ok'); return { ok: true, usuario: u };
  }
  function remover(id) {
    const c = conta(); if (!souPrimario()) return { ok: false, motivo: 'Somente o usuário primário pode remover pessoas.' };
    if (id === c.primario) return { ok: false, motivo: 'O usuário primário não pode ser removido da própria conta.' };
    c.usuarios = c.usuarios.filter(u => u.id !== id); auditar('removeu usuário ' + id, 'ok'); return { ok: true };
  }
  const SIT = { aprovado: ['APROVADO', '#e9f6ee', '#1e7b45'], analise: ['EM ANÁLISE', '#fff4d6', '#7a5200'], suspenso: ['SUSPENSO', '#fdecee', '#a3232b'] };
  function situacao(id, sit) {
    const c = conta(); if (!souPrimario()) { auditar('tentativa de alterar situação por secundário', 'bloqueado'); return { ok: false, motivo: 'Somente o usuário primário pode alterar a situação das pessoas.' }; }
    if (id === c.primario) return { ok: false, motivo: 'A situação do usuário primário não pode ser alterada.' };
    const u = c.usuarios.find(x => x.id === id); if (!u || !SIT[sit]) return { ok: false, motivo: 'Situação inválida.' };
    const ant = u.sit || 'aprovado'; u.sit = sit; u.sitEm = Date.now(); auditar(u.nome + ': ' + SIT[ant][0].toLowerCase() + ' → ' + SIT[sit][0].toLowerCase(), 'ok'); return { ok: true };
  }
  function excluirConta(id) {
    const c = R.contas[id], u = atual() || window.BLUEJUS_USUARIO_ATUAL || {};
    if (!c || c.demo) return { ok: false, motivo: 'Esta conta não pode ser excluída.' };
    const pode = u.id === c.primario && R.ativa === id || (u.email || '').toLowerCase() === MASTER_EMAIL || c.usuarios.some(x => x.id === (u.id || '') && x.id === c.primario);
    if (!pode) { auditar('tentativa de excluir conta ' + id, 'bloqueado'); return { ok: false, motivo: 'Somente o usuário primário da conta (ou o Master) pode excluí-la.' }; }
    const pfx = 'bjt:' + id + ':'; for (let i = LS.length - 1; i >= 0; i--) { const k = LS.key(i); if (k && k.indexOf(pfx) === 0) raw.rem.call(LS, k); }
    delete R.contas[id]; if (R.sessao) delete R.sessao[id]; if (R.ativa === id) R.ativa = DEMO; auditar('conta excluída: ' + c.escritorio, 'ok'); return { ok: true };
  }
  function entrarComo(id) { const c = conta(), uu = c.usuarios.find(u => u.id === id); if (!uu) return; if (uu.sit === 'suspenso') { aviso('Acesso suspenso: ' + uu.nome + ' não pode entrar enquanto estiver suspenso(a).'); return; } R.sessao = Object.assign({}, R.sessao, { [c.id]: id }); gravar(R); recarregar(); }
  function trocarConta(id) { if (!R.contas[id]) return; R.ativa = id; gravar(R); recarregar(); }

  /* ---------- Interface ---------- */
  const css = 'font-family:Arial,Helvetica,sans-serif;';
  const inp = 'width:100%;box-sizing:border-box;height:38px;border:1px solid #c9d6df;border-radius:4px;padding:0 11px;font:inherit;font-size:14px;color:#12242f;background:#fff;outline:none;';
  const btn = (bg, c) => 'height:38px;border:none;border-radius:4px;background:' + bg + ';color:' + c + ';font:inherit;font-size:14px;font-weight:bold;padding:0 16px;cursor:pointer;transition:transform .15s,background .15s;';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  let el = null, aba = 'conta', msg = '';
  function fechar() { if (el) { el.remove(); el = null; } document.removeEventListener('keydown', escK); }
  const escK = e => { if (e.key === 'Escape') fechar(); };
  function indicar() {
    const c = conta(), u = atual() || {}, ref = (u.id || 'u') + '-' + c.id, base = location.href.replace(/[^/]*$/, ''), longo = base + '06 - Trabalho Externo - VS 18.10.26.dc.html?origem=indicacao&cadastro=1&ref=' + encodeURIComponent(ref);
    const cod = (() => { let h = 0; String(ref).split('').forEach(ch => { h = (h * 33 + ch.charCodeAt(0)) >>> 0; }); return h.toString(36).toUpperCase().slice(0, 6).padStart(6, 'B'); })(), link = 'https://bluejus.com.br/i/' + cod;
    try { const M = JSON.parse(LS.getItem('bj-links-curtos') || '{}'); M[cod] = longo; LS.setItem('bj-links-curtos', JSON.stringify(M)); } catch (e) {}
    const pnome = String(u.nome || '').trim(), pri = pnome.split(' ')[0] || '';
    const msgP = 'Olá! Aqui é ' + (pnome || 'um colega') + '. Uso o BlueJus no meu escritório para controlar prazos, processos e documentos em um só lugar, e é grátis para começar. Crie a sua conta pelo meu convite: ' + link;
    let PF = {}; try { PF = JSON.parse(LS.getItem('bj-meu-perfil') || '{}'); } catch (e) {} const meuPerfil = String(PF.email || '').toLowerCase() === String(u.email || '').toLowerCase() ? PF : {};
    const falta = [!pnome || pnome.split(' ').length < 2 ? 'nome completo' : '', !(meuPerfil.telefone || u.telefone) ? 'telefone' : '', !(meuPerfil.cpf || u.cpf) ? 'CPF' : ''].filter(Boolean);
    let CFG = {}; try { CFG = Object.assign({ bloqueado: false, dia: 20, mes: 200, alerta: 30, bloq: {} }, JSON.parse(LS.getItem('bj-indic-cfg') || '{}')); } catch (e) { CFG = { bloqueado: false, dia: 20, mes: 200, alerta: 30, bloq: {} }; }
    const enviadas = () => { try { return (JSON.parse(LS.getItem('bj-indicacoes') || '[]')).filter(x => x.por === u.id); } catch (e) { return []; } };
    const travado = () => { if (CFG.bloqueado) return 'Os convites estão pausados pela administração do BlueJus no momento.'; if ((CFG.bloq || {})[u.id]) return 'Seus envios de convite foram pausados pela administração do BlueJus.'; const E = enviadas(), d1 = E.filter(x => Date.now() - x.em < 864e5).length, d30 = E.filter(x => Date.now() - x.em < 30 * 864e5).length; if (d1 >= CFG.dia) return 'Você chegou ao limite de ' + CFG.dia + ' convites por dia. Tente novamente amanhã.'; if (d30 >= CFG.mes) return 'Você chegou ao limite de ' + CFG.mes + ' convites por mês.'; return ''; };
    let ov = document.getElementById('bji2-ov'); if (ov) ov.remove(); ov = document.createElement('div'); ov.id = 'bji2-ov';
    ov.style.cssText = css + 'position:fixed;inset:0;z-index:9850;background:rgba(14,37,51,.38);display:flex;align-items:center;justify-content:center;padding:12px;color:#12242f;';
    const L = () => { try { return (JSON.parse(LS.getItem('bj-indicacoes') || '[]')).filter(x => x.por === u.id); } catch (e) { return []; } };
    const desenhaI = (aviso) => { const H = L();
      ov.innerHTML = '<div style="width:min(520px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:4px;box-shadow:0 20px 50px rgba(18,36,47,.25);">'
        + '<div style="background:#0a6fa0;color:#fff;padding:13px 18px;display:flex;align-items:center;gap:10px;"><b style="flex:1;font-size:16px;">Indicar o BlueJus</b><button data-x style="border:none;background:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;">×</button></div>'
        + '<div style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;">'
        + '<div style="display:flex;gap:10px;align-items:flex-start;background:#f2f8fc;border:1px solid #cfe3f0;border-radius:4px;padding:10px 12px;font-size:13px;color:#34505f;line-height:1.5;"><span style="font-size:18px;">💡</span><span>Convide colegas e outros escritórios para <b>criar a própria conta</b> no BlueJus. Quem aceitar abre um escritório novo e não entra na sua equipe.' + (souPrimario() ? ' Para chamar alguém para a <b>sua equipe</b>, use <b>Convites do escritório</b>.' : ' Quem chama pessoas para a equipe deste escritório é o usuário primário.') + '</span></div>'
        + (falta.length ? '<div style="background:#fff8e6;border:1px solid #f1e2bf;border-radius:4px;padding:12px 14px;font-size:13px;color:#5a3a06;line-height:1.5;"><b>Complete seu cadastro para enviar convites</b><br>Por segurança, o convite sempre leva o seu nome, então precisamos do seu ' + esc(falta.join(', ')) + '.<div style="margin-top:10px;"><a href="./07 - Meu Perfil - VS 18.10.26.dc.html" style="' + btn('#0a6fa0', '#fff') + 'display:inline-flex;align-items:center;text-decoration:none;">Completar meu cadastro</a></div></div>' : travado() ? '<div style="background:#fdf1f2;border:1px solid #f1c4c9;border-radius:4px;padding:12px 14px;font-size:13px;color:#842029;">' + esc(travado()) + '</div>' : ('<div style="font-size:12px;color:#5b7383;">O convite é enviado em seu nome: <b>' + esc(pnome) + '</b> · link curto <b>' + esc(link) + '</b></div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><input id="bji2-n" placeholder="Nome (opcional)" style="' + inp + '"><input id="bji2-e" placeholder="E-mail" style="' + inp + '"></div>'
        + '<textarea id="bji2-m" rows="4" style="' + inp + 'height:auto;padding:9px 11px;line-height:1.5;resize:vertical;">' + esc(msgP) + '</textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;"><button data-env style="' + btn('#0a6fa0', '#fff') + '">Enviar convite por e-mail</button><button data-wa style="' + btn('#1e7b45', '#fff') + '">WhatsApp</button><button data-cp style="' + btn('#fff', '#0a6fa0') + 'border:1px solid #c9d6df;">Copiar link curto</button></div>'))
        + (aviso ? '<div style="font-size:13px;color:#1e7b45;">' + esc(aviso) + '</div>' : '')
        + '<div style="font-size:12px;font-weight:bold;letter-spacing:.5px;color:#5f7684;margin-top:4px;">SUAS INDICAÇÕES (' + H.length + ')</div>'
        + (H.length ? H.slice(0, 8).map(x => '<div style="display:flex;gap:10px;align-items:center;font-size:13px;padding:6px 0;border-bottom:1px solid #eef2f5;"><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(x.nome ? x.nome + ' · ' : '') + esc(x.email || x.canal) + '</span><span style="color:#7a8e9b;">' + new Date(x.em).toLocaleDateString('pt-BR') + '</span><span style="font-size:11px;font-weight:bold;padding:2px 7px;border-radius:3px;background:' + (x.st === 'aceita' ? '#e9f6ee;color:#1e7b45' : '#fff4d6;color:#7a5200') + ';">' + (x.st === 'aceita' ? 'CONTA CRIADA' : 'ENVIADA') + '</span></div>').join('') : '<div style="font-size:12.5px;color:#9aabb7;">Nenhuma indicação ainda.</div>')
        + '</div></div>';
      const reg = (canal, email, nome) => { const A = JSON.parse(LS.getItem('bj-indicacoes') || '[]'); A.unshift({ por: u.id, porNome: pnome, porEmail: u.email, conta: c.escritorio, ref, cod, canal, email, nome, em: Date.now(), st: 'enviada' }); LS.setItem('bj-indicacoes', JSON.stringify(A.slice(0, 1000))); auditar('indicação do BlueJus por ' + pnome + ' via ' + canal, 'ok');
        const d1 = A.filter(x => x.por === u.id && Date.now() - x.em < 864e5).length; if (d1 === CFG.alerta) { try { window.BJCentral && window.BJCentral.notificar && window.BJCentral.notificar({ tipo: 'alerta', para: 'master', titulo: 'Disparo alto de convites', texto: pnome + ' (' + c.escritorio + ') enviou ' + d1 + ' convites nas últimas 24h. Revise em Painel do Master › Comercial › Indicações.', link: './05 - Perfis 1.2 - VS 21.10.26.dc.html#aba=painel&sec=com' }); } catch (e) {} try { const N = JSON.parse(LS.getItem('bj-alertas-master') || '[]'); N.unshift({ em: Date.now(), txt: pnome + ' enviou ' + d1 + ' convites em 24h', por: u.id }); LS.setItem('bj-alertas-master', JSON.stringify(N.slice(0, 50))); } catch (e) {} } };
      const pode = () => !falta.length && !travado();
      ov.querySelector('[data-x]').onclick = () => ov.remove();
      const bE = ov.querySelector('[data-env]'); if (bE) bE.onclick = () => { if (!pode()) return desenhaI(''); const em = ov.querySelector('#bji2-e').value.trim().toLowerCase(), nm = ov.querySelector('#bji2-n').value.trim(); if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) return desenhaI('Informe um e-mail válido.'); if (c.usuarios.some(x => x.email === em)) return desenhaI('Esta pessoa já faz parte da sua conta.'); try { if ((JSON.parse(LS.getItem('bj-com-optout') || '[]')).includes(em)) return desenhaI('Este e-mail pediu para não receber mensagens do BlueJus. O convite não pode ser enviado.'); } catch (e) {} reg('e-mail', em, nm); try { const F = JSON.parse(LS.getItem('bj-com-fila') || '[]'); F.unshift({ gat: 'Indicação', assunto: pnome + ' convidou você para o BlueJus', para: em, em: Date.now() }); LS.setItem('bj-com-fila', JSON.stringify(F.slice(0, 50))); } catch (e) {} desenhaI('Convite enviado para ' + em + '. Obrigado por indicar o BlueJus!'); };
      const bW = ov.querySelector('[data-wa]'); if (bW) bW.onclick = () => { if (!pode()) return desenhaI(''); reg('WhatsApp'); window.open('https://wa.me/?text=' + encodeURIComponent(ov.querySelector('#bji2-m').value), '_blank', 'noopener'); desenhaI('Mensagem aberta no WhatsApp.'); };
      const bC = ov.querySelector('[data-cp]'); if (bC) bC.onclick = () => { if (!pode()) return desenhaI(''); navigator.clipboard && navigator.clipboard.writeText(link); reg('link'); desenhaI('Link copiado.'); };
      ov.onclick = e => { if (e.target === ov) ov.remove(); }; };
    desenhaI(''); document.body.appendChild(ov);
  }
  function tela(a) {
    aba = a || aba; if (!souPrimario() && aba !== 'trocar') aba = 'trocar'; msg = ''; if (!el) { el = document.createElement('div'); document.body.appendChild(el); document.addEventListener('keydown', escK); }
    el.style.cssText = 'position:fixed;inset:0;z-index:9800;background:rgba(14,37,51,.38);display:flex;align-items:center;justify-content:center;padding:16px;' + css;
    el.onclick = e => { if (e.target === el) fechar(); };
    desenhar();
  }
  function desenhar() {
    const c = conta(), u = atual() || window.BLUEJUS_USUARIO_ATUAL || {}, prim = souPrimario();
    const tab = (k, t) => '<button data-aba="' + k + '" style="border:none;background:none;padding:12px 4px 10px;margin-right:18px;font:inherit;font-size:14px;font-weight:bold;cursor:pointer;color:' + (aba === k ? '#0a6fa0' : '#5f7684') + ';border-bottom:3px solid ' + (aba === k ? '#0a6fa0' : 'transparent') + ';">' + t + '</button>';
    let corpo = '';
    if (aba === 'conta') {
      corpo = '<div style="display:flex;flex-direction:column;gap:14px;">'
        + '<div style="background:#f3f7fa;border-radius:4px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:13.5px;"><span style="color:#5f7684;">Escritório</span><b>' + esc(c.escritorio) + '</b><span style="color:#5f7684;">Você</span><span>' + esc(u.nome || '') + ' · <b style="color:' + (prim ? '#0a6fa0' : '#5f7684') + ';">' + (prim ? 'Usuário primário' : 'Secundário') + '</b></span></div>'
        + '<div style="font-size:12px;font-weight:bold;letter-spacing:.5px;color:#5f7684;">EQUIPE DA CONTA (' + c.usuarios.length + ')</div>'
        + '<div style="max-height:220px;overflow:auto;border:1px solid #e1e8ed;border-radius:4px;">' + c.usuarios.map(x => '<div data-lin="' + esc(x.id) + '" style="display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid #eef2f5;font-size:14px;"><span style="width:30px;height:30px;border-radius:50%;background:' + (x.id === c.primario ? '#0a6fa0' : '#8ea3b1') + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;flex:none;">' + esc(x.nome.split(' ').slice(0, 2).map(p => p[0]).join('')) + '</span><span style="flex:1;min-width:0;"><span style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(x.nome) + '</span><span style="font-size:12px;color:#62798a;">' + esc(x.funcao || '') + ' · ' + esc(x.email) + '</span></span><span style="font-size:11px;font-weight:bold;padding:3px 8px;border-radius:3px;background:' + (x.id === c.primario ? '#e3f0f8' : '#eef2f5') + ';color:' + (x.id === c.primario ? '#0a6fa0' : '#5f7684') + ';">' + (x.id === c.primario ? 'PRIMÁRIO' : 'SECUNDÁRIO') + '</span>' + (prim && x.id !== c.primario ? (() => { const st = SIT[x.sit || 'aprovado']; return '<span title="Situação" style="font-size:11px;font-weight:bold;padding:3px 8px;border-radius:3px;background:' + st[1] + ';color:' + st[2] + ';white-space:nowrap;">' + st[0] + '</span>'; })() : '') + (prim ? '<button data-mnu="' + esc(x.id) + '" title="Opções" style="width:26px;height:26px;border:none;border-radius:3px;background:none;color:#5f7684;cursor:pointer;font-size:16px;line-height:1;" onmouseover="this.style.background=\'#e6edf2\'" onmouseout="this.style.background=\'none\'">⋮</button>' : '') + '</div>').join('') + '</div>'
        + (prim ? '<div style="font-size:12px;font-weight:bold;letter-spacing:.5px;color:#5f7684;margin-top:4px;">CONVIDAR (ENTRA COMO SECUNDÁRIO)</div><div style="font-size:12px;color:#62798a;margin-top:-4px;">Clique com o botão direito (ou em ⋮) numa pessoa para aprovar, suspender, voltar para análise ou demitir.</div><div style="display:grid;grid-template-columns:1fr 1fr 140px;gap:8px;"><input id="bjc-cn" placeholder="Nome" style="' + inp + '"><input id="bjc-ce" placeholder="E-mail" style="' + inp + '"><select id="bjc-cf" style="' + inp + '"><option>Advogado</option><option>Advogada</option><option>Controladoria</option><option>Coordenadora</option><option>Assistente</option><option>Estagiário</option><option>Financeiro</option></select></div><div style="display:flex;justify-content:flex-end;"><button data-act="convidar" style="' + btn('#0a6fa0', '#fff') + '">Enviar convite</button></div>'
          : '<div style="font-size:13px;color:#62798a;background:#f3f7fa;border-radius:4px;padding:10px 12px;">A gestão da equipe é feita pelo usuário primário da conta.</div>')
        + '</div>';
    } else if (aba === 'nova') {
      corpo = '<div style="display:flex;flex-direction:column;gap:10px;"><div style="font-size:13.5px;color:#34505f;line-height:1.5;">Quem abre a conta se torna o <b>usuário primário</b> (gestor do escritório). As pessoas convidadas depois entram como secundárias. Os dados de cada conta ficam separados e não são vistos por outras contas.</div>'
        + '<input id="bjc-ne" placeholder="Nome do escritório" style="' + inp + '"><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><input id="bjc-nn" placeholder="Seu nome completo" style="' + inp + '"><input id="bjc-nm" placeholder="Seu e-mail" style="' + inp + '"></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><input id="bjc-no" placeholder="OAB (opcional)" style="' + inp + '"><select id="bjc-nf" style="' + inp + '"><option>Advogado</option><option>Advogada</option><option>Sócio</option><option>Sócia</option></select></div>'
        + '<div style="display:flex;justify-content:flex-end;"><button data-act="abrir" style="' + btn('#0a6fa0', '#fff') + '">Abrir conta</button></div></div>';
    } else {
      const L = Object.values(R.contas);
      corpo = '<div style="display:flex;flex-direction:column;gap:8px;">' + L.map(x => '<button data-conta="' + esc(x.id) + '" style="display:flex;align-items:center;gap:12px;text-align:left;border:1px solid ' + (x.id === c.id ? '#0a6fa0' : '#e1e8ed') + ';background:' + (x.id === c.id ? '#eef6fb' : '#fff') + ';border-radius:4px;padding:11px 13px;font:inherit;cursor:pointer;"><i class="bi bi-building" style="font-size:18px;color:#0a6fa0;"></i><span style="flex:1;min-width:0;"><b style="display:block;font-size:14px;color:#12242f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(x.escritorio) + '</b><span style="font-size:12px;color:#62798a;">' + x.usuarios.length + ' usuário(s) · primário: ' + esc((x.usuarios.find(y => y.id === x.primario) || {}).nome || '') + '</span></span>' + (x.id === c.id ? '<span style="font-size:11px;font-weight:bold;color:#0a6fa0;">ATUAL</span>' : '') + (!x.demo ? '<span data-exc="' + esc(x.id) + '" title="Excluir conta" style="color:#a3232b;font-size:12px;font-weight:bold;padding:2px 6px;">Excluir</span>' : '') + '</button>').join('') + '<div style="font-size:12px;color:#62798a;margin-top:4px;">No sistema real cada pessoa só vê as contas a que pertence. Aqui a troca serve para demonstrar o isolamento.</div></div>';
    }
    el.innerHTML = '<div role="dialog" aria-label="Contas" style="width:min(620px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:4px;box-shadow:0 26px 60px -20px rgba(12,48,72,.45);">'
      + '<div style="background:#0a6fa0;color:#fff;padding:14px 18px;display:flex;align-items:center;gap:10px;"><b style="font-size:16px;flex:1;">Conta do escritório</b><button data-x="1" style="border:none;background:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;">×</button></div>'
      + '<div style="padding:0 18px;border-bottom:1px solid #e1e8ed;">' + (souPrimario() ? tab('conta', 'Minha conta') + tab('nova', 'Abrir conta') : '') + tab('trocar', 'Trocar conta') + '</div>'
      + '<div style="padding:18px;">' + corpo + '<div id="bjc-msg" style="min-height:18px;margin-top:10px;font-size:13px;color:#a3232b;">' + esc(msg) + '</div></div></div>';
    el.querySelectorAll('[data-aba]').forEach(b => b.onclick = () => { aba = b.dataset.aba; msg = ''; desenhar(); });
    el.querySelector('[data-x]').onclick = fechar;
    el.querySelectorAll('[data-rem]').forEach(b => b.onclick = () => { if (!confirm('Remover esta pessoa da conta?')) return; const r = remover(b.dataset.rem); msg = r.ok ? '' : r.motivo; desenhar(); });
    el.querySelectorAll('[data-como]').forEach(b => b.onclick = () => entrarComo(b.dataset.como));
    const abrirMnu = (id, x, y) => { const c = conta(), me = atual() || {}, pr = souPrimario(), alvo = c.usuarios.find(z => z.id === id); if (!alvo) return; const sit = alvo.sit || 'aprovado', ehP = id === c.primario; let m = document.getElementById('bjc-mnu'); if (m) m.remove(); m = document.createElement('div'); m.id = 'bjc-mnu';
      const it = (k, ic, t, cor) => '<button data-op="' + k + '" style="width:100%;display:flex;align-items:center;gap:10px;border:none;background:#fff;padding:8px 14px;font:inherit;font-size:13.5px;color:' + (cor || '#243845') + ';cursor:pointer;text-align:left;" onmouseover="this.style.background=\'#eef5fa\'" onmouseout="this.style.background=\'#fff\'"><span style="width:16px;text-align:center;">' + ic + '</span>' + t + '</button>';
      const L = []; if (pr && !ehP) { if (sit !== 'aprovado') L.push(it('aprovado', '✓', sit === 'suspenso' ? 'Reativar (aprovar)' : 'Aprovar', '#1e7b45')); if (sit !== 'analise') L.push(it('analise', '↺', 'Voltar para análise')); if (sit !== 'suspenso') L.push(it('suspenso', '⏸', 'Suspender acesso', '#9a5b00')); L.push('<div style="height:1px;background:#eef2f5;margin:4px 0;"></div>' + it('demitir', '✕', 'Demitir (remover da conta)', '#a3232b')); }
      if (id !== me.id) L.push((L.length ? '<div style="height:1px;background:#eef2f5;margin:4px 0;"></div>' : '') + it('como', '↪', 'Entrar como (demonstração)', '#0a6fa0'));
      if (!L.length) L.push('<div style="padding:8px 14px;font-size:12.5px;color:#7a8e9b;">Sem ações disponíveis</div>');
      m.style.cssText = css + 'position:fixed;z-index:100001;left:' + Math.min(x, innerWidth - 250) + 'px;top:' + Math.min(y, innerHeight - 230) + 'px;width:240px;background:#fff;border:1px solid #d3dde4;border-radius:4px;box-shadow:0 10px 28px rgba(18,36,47,.2);padding:4px 0;';
      m.innerHTML = '<div style="padding:7px 14px 8px;font-size:12px;color:#62798a;border-bottom:1px solid #eef2f5;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(alvo.nome) + (ehP ? ' · primário' : ' · ' + SIT[sit][0].toLowerCase()) + '</div>' + L.join('');
      document.body.appendChild(m); const fora = ev => { if (!m.contains(ev.target)) { m.remove(); document.removeEventListener('mousedown', fora, true); } }; setTimeout(() => document.addEventListener('mousedown', fora, true), 0);
      m.querySelectorAll('[data-op]').forEach(bt => bt.onclick = () => { const op = bt.dataset.op; m.remove(); document.removeEventListener('mousedown', fora, true);
        if (op === 'como') return entrarComo(id);
        if (op === 'demitir') { if (!confirm('Demitir ' + alvo.nome + '? A pessoa perde o acesso a esta conta. É a mesma ação de "Demitir" da tela de equipe.')) return; const r = remover(id); msg = r.ok ? '' : r.motivo; if (r.ok) aviso(alvo.nome + ' foi removido(a) da conta'); return desenhar(); }
        if (op === 'suspenso' && !confirm('Suspender o acesso de ' + alvo.nome + '? Os dados e o histórico continuam guardados.')) return;
        const r = situacao(id, op); msg = r.ok ? '' : r.motivo; if (r.ok) aviso(alvo.nome + ': ' + SIT[op][0].toLowerCase()); desenhar(); }); };
    el.querySelectorAll('[data-mnu]').forEach(bt => bt.onclick = e => { e.stopPropagation(); const r = bt.getBoundingClientRect(); abrirMnu(bt.dataset.mnu, r.right - 240, r.bottom + 4); });
    el.querySelectorAll('[data-lin]').forEach(ln => ln.oncontextmenu = e => { e.preventDefault(); abrirMnu(ln.dataset.lin, e.clientX, e.clientY); });
    el.querySelectorAll('[data-exc]').forEach(b => b.onclick = e => { e.stopPropagation(); if (!confirm('Excluir esta conta e todos os dados dela? Esta ação não pode ser desfeita.')) return; const r = excluirConta(b.dataset.exc); msg = r.ok ? '' : r.motivo; if (r.ok) { aviso('Conta excluída'); recarregar(); } desenhar(); });
    el.querySelectorAll('[data-conta]').forEach(b => b.onclick = () => { if (b.dataset.conta !== conta().id) trocarConta(b.dataset.conta); });
    const v = id => (el.querySelector('#' + id) || {}).value;
    const a = el.querySelector('[data-act="convidar"]'); if (a) a.onclick = () => { const r = convidar({ nome: v('bjc-cn'), email: v('bjc-ce'), funcao: v('bjc-cf') }); msg = r.ok ? '' : r.motivo; desenhar(); if (r.ok) aviso(r.usuario.nome + ' foi convidado(a) como secundário(a)'); };
    const b2 = el.querySelector('[data-act="abrir"]'); if (b2) b2.onclick = () => { const r = abrirConta({ escritorio: v('bjc-ne'), nome: v('bjc-nn'), email: v('bjc-nm'), oab: v('bjc-no'), funcao: v('bjc-nf') }); if (!r.ok) { msg = r.motivo; return desenhar(); } aviso('Conta aberta. Você é o usuário primário.'); recarregar(); };
  }
  function aviso(t) { const d = document.createElement('div'); d.textContent = t; d.style.cssText = css + 'position:fixed;z-index:9900;left:50%;bottom:26px;transform:translateX(-50%);background:#12242f;color:#fff;font-size:14px;padding:10px 16px;border-radius:4px;box-shadow:0 8px 24px rgba(0,0,0,.25);'; document.body.appendChild(d); setTimeout(() => d.remove(), 2600); }

  window.BJContas = {
    conta: () => { const c = conta(); return { id: c.id, escritorio: c.escritorio, primario: c.primario, demo: !!c.demo, usuarios: c.usuarios.map(u => Object.assign({}, u)) }; },
    usuario: () => atual() || window.BLUEJUS_USUARIO_ATUAL || null,
    souPrimario, papel: id => { const c = conta(); return id === c.primario ? 'primario' : (c.usuarios.some(u => u.id === id) ? 'secundario' : null); },
    todosUsuarios: () => Object.values(R.contas || {}).flatMap(c => (c.usuarios || []).map(u => Object.assign({}, u, { conta: c.id, escritorio: c.escritorio, ehPrimario: u.id === c.primario }))), indicar, abrirConta, convidar, remover, situacao, excluirConta, entrarComo, trocarConta, tela, log: () => (R.log || []).filter(x => x.conta === R.ativa)
  };
})();
