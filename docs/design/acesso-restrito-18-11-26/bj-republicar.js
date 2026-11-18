/* BlueJus · Republicar pasta (colaboração entre contas, protótipo)
   Mesmo conceito do "republicar" das redes sociais: o dono da pasta a republica para outro usuário,
   identificado pelo código pessoal (BJ-XXXX-XXXX-XXXX). O destinatário aceita e a pasta aparece na conta dele,
   marcada como "Recebida", com as permissões concedidas. Uma conta recebe pastas de várias outras, sem precisar
   ter login em cada uma. A pasta continua do dono, que pode encerrar o acesso a qualquer momento.
   Protótipo: localStorage 'bj-republicadas'. Sistema real: tabela pasta_compartilhamento
   (id, pasta_id, conta_origem_id, usuario_origem_id, usuario_destino_id, permissoes[], validade, status, mensagem,
   criado_em, aceito_em, encerrado_em, encerrado_por) e verificação no servidor em toda rota da pasta:
   o destinatário só acessa a pasta compartilhada (e os documentos dela), nunca o resto da conta de origem. */
(function () {
  if (window.BJRepublicar) return;
  const K = 'bj-republicadas', KSEED = 'bj-republicadas-demo';
  const ler = () => { try { return JSON.parse(localStorage.getItem(K)) || []; } catch (e) { return []; } };
  const grava = L => { localStorage.setItem(K, JSON.stringify(L.slice(0, 500))); try { dispatchEvent(new CustomEvent('bj-republicar')); } catch (e) {} };
  const conta = () => { const B = window.BJContas, c = B && B.conta ? B.conta() : {}, u = B && B.usuario ? B.usuario() : {}; return { conta: c.escritorio || 'Meu escritório', contaId: c.id || 'demo', usuario: (u && u.nome) || 'Farlei Assis da Rocha', uid: (u && (u.id || u.email)) || 'farlei' }; };
  const A = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const meuCodigo = () => { const B = window.BJContas, me = conta(); if (B && B.codigoConta) return B.codigoConta('usr:' + me.uid); let c = localStorage.getItem('bj-meu-codigo'); if (!c) { let s = ''; for (let i = 0; i < 12; i++) s += A[Math.floor(Math.random() * A.length)]; c = 'BJ-' + s.slice(0, 4) + '-' + s.slice(4, 8) + '-' + s.slice(8); localStorage.setItem('bj-meu-codigo', c); } return c; };
  const normCod = t => { const s = String(t || '').toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/^BJ/, '').slice(0, 12); return s.length ? 'BJ-' + [s.slice(0, 4), s.slice(4, 8), s.slice(8, 12)].filter(Boolean).join('-') : ''; };
  const nI = t => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/\s+/g, ' ').trim();
  const valido = c => /^BJ-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/.test(c);
  /* Diretório de demonstração: no sistema real, o servidor confirma o código e devolve nome e escritório. */
  const DIR = { 'BJ-MURA-2LMS-SM7A': ['Simone Moura', 'Moura & Lima Advocacia'], 'BJ-RDRG-5CAR-LS9X': ['Carlos Rodrigues', 'Escritório Rodrigues Associados'], 'BJ-PERE-4ADV-GZ8K': ['Ana Pereira', 'Pereira Advogados'] };
  const quem = c => DIR[c] ? { nome: DIR[c][0], conta: DIR[c][1] } : null;
  const hoje = () => new Date().toISOString().slice(0, 10);
  const vencida = r => r.ate && r.ate < hoje();
  const ativa = r => (r.st === 'aceita' || r.st === 'pendente') && !vencida(r);
  function semear() { if (localStorage.getItem(KSEED)) return; const L = ler(), eu = meuCodigo(); L.push({ id: 'rp-demo1', nup: 90015, cliente: 'CONDOMÍNIO RESIDENCIAL JARDINS (EXEMPLO)', acao: 'COBRANÇA DE TAXAS CONDOMINIAIS', data: '2026-10-02', de: { conta: 'Moura & Lima Advocacia', usuario: 'Simone Moura', codigo: 'BJ-MURA-2LMS-SM7A' }, para: { codigo: eu, nome: conta().usuario, conta: conta().conta }, perms: { mov: true, docs: true, baixar: false }, metas: 2, ate: '', msg: 'Pode acompanhar os prazos desta cobrança comigo?', st: 'pendente', em: Date.now() - 36e5, hist: [{ em: Date.now() - 36e5, t: 'Republicada por Simone Moura' }] }); grava(L); localStorage.setItem(KSEED, '1'); }
  /* Equipe do escritório: mesmo fluxo, sem código e sem aceite (o usuário já pertence à conta). */
  function enviarInterno(o) {
    const me = conta(), alvo = nI(o.usuario); if (!alvo) return { ok: false, erro: 'Escolha o usuário da equipe.' };
    const L = ler(); if (L.some(r => r.nup === o.nup && r.para.interno && nI(r.para.nome) === alvo && ativa(r))) return { ok: false, erro: 'A pasta já está compartilhada com esse usuário.' };
    const r = { id: 'rp-' + Date.now().toString(36), nup: o.nup, cliente: o.cliente, acao: o.acao, data: o.data || hoje(), de: { conta: me.conta, usuario: me.usuario, codigo: meuCodigo() }, para: { codigo: 'INT:' + alvo, nome: o.usuario, conta: me.conta, interno: true }, perms: Object.assign({ mov: false, docs: false, baixar: false }, o.perms || {}), ate: o.ate || '', msg: String(o.msg || '').slice(0, 300), st: 'aceita', em: Date.now(), hist: [{ em: Date.now(), t: (o.motivo || 'Compartilhada com a equipe') + ' por ' + me.usuario }] };
    L.unshift(r); grava(L); return { ok: true, r };
  }
  /* Pastas internas compartilhadas com um usuário da equipe (vale para o acesso restrito). */
  const internasDe = nome => ler().filter(r => r.para.interno && nI(r.para.nome) === nI(nome) && ativa(r));
  function enviar(o) {
    if (o.interno) return enviarInterno(o);
    const cod = normCod(o.codigo), me = conta(); if (!valido(cod)) return { ok: false, erro: 'Código inválido. Use o formato BJ-XXXX-XXXX-XXXX.' };
    if (cod === meuCodigo()) return { ok: false, erro: 'Este é o seu próprio código.' };
    const L = ler(); if (L.some(r => r.nup === o.nup && r.de.codigo === meuCodigo() && r.para.codigo === cod && ativa(r))) return { ok: false, erro: 'Esta pasta já está republicada para esse usuário.' };
    const q = quem(cod), r = { id: 'rp-' + Date.now().toString(36), nup: o.nup, cliente: o.cliente, acao: o.acao, data: o.data || hoje(), de: { conta: me.conta, usuario: me.usuario, codigo: meuCodigo() }, para: { codigo: cod, nome: q ? q.nome : '', conta: q ? q.conta : '' }, perms: Object.assign({ mov: false, docs: false, baixar: false }, o.perms || {}), ate: o.ate || '', msg: String(o.msg || '').slice(0, 300), st: 'pendente', em: Date.now(), hist: [{ em: Date.now(), t: 'Republicada por ' + me.usuario }] };
    L.unshift(r); grava(L); return { ok: true, r };
  }
  const muda = (id, st, t) => { const L = ler(), r = L.find(x => x.id === id); if (!r) return false; r.st = st; r.hist = (r.hist || []).concat({ em: Date.now(), t }); grava(L); return true; };
  const eu = () => meuCodigo();
  window.BJRepublicar = {
    meuCodigo, normCod, valido, quem, ativa, vencida, internasDe,
    compartilhadaCom: (nup, nome) => internasDe(nome).some(r => String(r.nup) === String(nup)),
    enviar,
    enviadas: nup => ler().filter(r => (r.de.codigo === eu() || r.para.interno) && (nup == null || r.nup === nup)),
    recebidas: () => ler().filter(r => r.para.codigo === eu() && r.st !== 'revogada' && r.st !== 'recusada'),
    aceitas: () => ler().filter(r => r.para.codigo === eu() && r.st === 'aceita' && !vencida(r)),
    pendentes: () => ler().filter(r => r.para.codigo === eu() && r.st === 'pendente' && !vencida(r)),
    aceitar: id => muda(id, 'aceita', 'Aceita por ' + conta().usuario),
    recusar: id => muda(id, 'recusada', 'Recusada por ' + conta().usuario),
    revogar: id => muda(id, 'revogada', 'Acesso encerrado por ' + conta().usuario),
    sair: id => muda(id, 'revogada', 'Colaborador deixou a pasta')
  };
  if (document.readyState === 'loading') addEventListener('DOMContentLoaded', semear); else semear();
})();
