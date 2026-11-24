/* BlueJus · Painel do Master, janela "Convênios" (spec docs/specs/convenios.md §6.2 e §7, PR 4).
   Sem desenho: convenção do painel (host #mp-modal, cabeçalho .mp-cab-roxo, linhas .mp-lin, classes mp-lim-*).
   - O painel.js chama BJConvenios.abrir(ctx). ctx: { lista, salvar, ligar (com /0/), instituicao (com /__chave__/), token,
     irParaContas(), aoGravar() }. aoGravar só depois de uma gravação feita.
   - O estado vem do servidor (GET) e cada mudança é um POST JSON com X-CSRF-Token; a resposta traz a lista nova e a
     `mensagem`. As regras (abrangência, benefício, datas, valor) são do servidor: o `erro` do 422 aparece na janela.
   - O painel "Convênio OAB por estado" (seção Contas) continua sendo o dono da escolha por UF: aqui só há o atalho.
   - Textos no catálogo (master.js.convenios.*), lidos por BJ_T.t na hora de usar. */
(() => {
  'use strict';
  const PREFIXO = 'master.js.convenios.';
  const T = (chave, params) => window.BJ_T.t(PREFIXO + chave, params);
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ESC[m]);
  const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
  const BENEFICIOS = ['gratuidade', 'desconto_percentual', 'desconto_fixo', 'espaco_extra'];
  const ABRANGENCIAS = ['nacional', 'uf', 'instituicao'];
  const dataBr = d => (d ? String(d).split('-').reverse().join('/') : '');
  const dinheiro = cent => ((+cent || 0) / 100).toLocaleString(window.BJFormato.idioma(), { style: 'currency', currency: 'BRL' });
  const hojeLocal = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };

  let ctx = null;
  let host = null;
  let ligado = false; // listeners do host e do documento já presos
  let L = null; // { hoje, prazoMinimo, convenios, instituicoes }
  let tela = null; // { nome: 'carga' | 'lista' | 'form' | 'desligar', ... }
  let msg = null; // faixa verde
  let ocupado = false;

  const hoje = () => (L && L.hoje) || hojeLocal();

  const fetchJson = (url, opcoes) => fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opcoes))
    .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || T('erro_salvar'))))));

  function postar(url, corpo, aoDar, aoFalhar) {
    if (ocupado) return;
    ocupado = true; desenhar();
    fetchJson(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': ctx.token }, body: JSON.stringify(corpo) })
      .then(d => { ocupado = false; L = { hoje: d.hoje, prazoMinimo: d.prazoMinimo, convenios: d.convenios, instituicoes: d.instituicoes }; msg = d.mensagem || null; aoDar(d); if (ctx.aoGravar) ctx.aoGravar(); })
      .catch(e => { ocupado = false; aoFalhar(e.message || T('erro_salvar')); });
  }

  // ---------- Textos de cada campo ----------
  function abrangenciaTexto(c) {
    if (c.abrangencia === 'uf') return T('abr_uf', { uf: c.uf || '' });
    return T(c.abrangencia === 'instituicao' ? 'abr_instituicao' : 'abr_nacional');
  }

  function beneficioTexto(c) {
    const v = c.beneficioValor;
    if (c.beneficio === 'desconto_percentual') return T('ben_percentual', { n: v });
    if (c.beneficio === 'desconto_fixo') return T('ben_fixo', { valor: dinheiro(v) });
    if (c.beneficio === 'espaco_extra') return T('ben_espaco', { n: v });
    return T('ben_gratuidade');
  }

  const validadeTexto = c => (c.validoAte ? T('validade', { de: dataBr(c.validoDe), ate: dataBr(c.validoAte) }) : T('validade_sem_prazo', { de: dataBr(c.validoDe) }));

  function estadoTexto(c) {
    if (!c.ativo) return T('st_desligado');
    return c.encerraEm ? T('st_termina', { data: dataBr(c.encerraEm) }) : T('st_ligado');
  }

  // ---------- Casca da janela ----------
  const cabecalho = (titulo, voltar) => '<div class="mp-cab-roxo"><i class="bi bi-ticket-perforated" aria-hidden="true"></i><b id="mpCvTitulo">' + esc(titulo) + '</b>'
    + (voltar ? '<button type="button" class="mp-cv-cab-btn" data-cv-voltar><i class="bi bi-arrow-left" aria-hidden="true"></i> ' + esc(T('voltar')) + '</button>' : '')
    + '<button type="button" class="mp-cv-cab-btn" data-cv-fechar aria-label="' + esc(T('fechar')) + '"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>';

  const faixa = () => (msg ? '<div class="mp-cv-faixa" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i> ' + esc(msg) + '</div>' : '');
  const erroHtml = e => (e ? '<div class="mp-cv-erro" role="alert">' + esc(e) + '</div>' : '');

  function caixa(titulo, corpo, rodape, voltar) {
    return '<div class="mp-lim-fundo" data-cv-fundo><div class="mp-lim mp-cv-caixa" role="dialog" aria-modal="true" aria-labelledby="mpCvTitulo">'
      + cabecalho(titulo, voltar) + '<div class="mp-lim-corpo mp-cv-corpo">' + corpo + '</div>'
      + '<div class="mp-lim-rodape">' + rodape + '</div></div></div>';
  }

  const btnCancelar = rotulo => '<button type="button" class="mp-lim-cancelar" data-cv-voltar>' + esc(rotulo || T('cancelar')) + '</button>';

  function desenhar() {
    if (!host || !tela) return;
    if (tela.nome === 'carga') host.innerHTML = caixa(T('titulo'), '<div class="mp-cv-vazio">' + esc(tela.erro || T('carregando')) + '</div>', '<button type="button" class="mp-lim-cancelar" data-cv-fechar>' + esc(T('fechar')) + '</button>');
    else if (tela.nome === 'lista') host.innerHTML = desenharLista();
    else if (tela.nome === 'form') host.innerHTML = desenharForm();
    else if (tela.nome === 'desligar') host.innerHTML = desenharDesligar();
    if (tela.nome === 'desligar') atualizarPrevia();
    if (tela.nome === 'form') atualizarForm();
  }

  // ---------- Lista ----------
  function linha(c) {
    const termina = c.ativo && c.encerraEm;
    return '<div class="mp-lin mp-cv-lin' + (c.ativo ? '' : ' is-desligado') + '" data-cv-id="' + c.id + '">'
      + '<div class="mp-cv-nome mp-hv"><b>' + esc(c.nome) + '</b><span>' + esc(c.instituicao) + '</span></div>'
      + '<div class="mp-cv-meta mp-hv"><span>' + esc(abrangenciaTexto(c)) + '</span><span>' + esc(beneficioTexto(c)) + '</span></div>'
      + '<div class="mp-cv-meta mp-hv"><span>' + esc(T('contas', { n: c.contas })) + '</span><span>' + esc(validadeTexto(c)) + '</span></div>'
      + '<div class="mp-cv-estado mp-hv"><span class="' + (c.ativo ? (termina ? 'is-termina' : 'is-ligado') : 'is-off') + '">' + esc(estadoTexto(c)) + '</span>'
      + (termina ? '<button type="button" class="mp-convenio-link" data-cv-cancelar-fim="' + c.id + '">' + esc(T('cancelar_fim')) + '</button>' : '') + '</div>'
      + '<div class="mp-cv-acoes">'
      + (c.oab ? '<button type="button" class="mp-convenio-link" data-cv-por-estado>' + esc(T('por_estado')) + '</button>' : '')
      + '<button type="button" class="mp-convenio-link" data-cv-editar="' + c.id + '">' + esc(T('editar')) + '</button>'
      + '<button type="button" class="mp-convenio-alternar' + (c.ativo ? ' on' : '') + '" role="switch" aria-checked="' + c.ativo + '" data-cv-chave="' + c.id + '"'
      + ' title="' + esc(T(c.ativo ? 'dica_desligar' : 'dica_ligar')) + '"' + (ocupado ? ' disabled' : '') + '><span class="mp-convenio-trilho"><span></span></span></button></div></div>';
  }

  function desenharLista() {
    const corpo = erroHtml(tela.erro) + faixa() + (L.convenios.length ? '<div class="mp-cv-lista">' + L.convenios.map(linha).join('') + '</div>' : '<div class="mp-cv-vazio">' + esc(T('vazio')) + '</div>');
    return caixa(T('titulo'), corpo,
      '<button type="button" class="mp-btn-roxo" data-cv-novo><i class="bi bi-plus-lg" aria-hidden="true"></i> ' + esc(T('novo')) + '</button>'
      + '<button type="button" class="mp-lim-cancelar" data-cv-fechar>' + esc(T('fechar')) + '</button>');
  }

  // ---------- Criar e editar ----------
  const centavosParaTexto = cent => (cent / 100).toFixed(2).replace('.', ',');

  function novoForm(c) {
    const f = {
      id: c ? c.id : null, nome: c ? c.nome : '', instituicao: c ? c.instituicao : '', abrangencia: c ? c.abrangencia : 'nacional', uf: c && c.uf ? c.uf : 'SP',
      beneficio: c ? c.beneficio : 'gratuidade', valor: '', textoSelo: c ? c.textoSelo || '' : '', validoDe: c ? c.validoDe : hoje(), validoAte: c && c.validoAte ? c.validoAte : '',
      limite: c && c.limiteDeUsos != null ? String(c.limiteDeUsos) : '', codigo: c ? c.codigoConvite : null, oab: !!(c && c.oab), erro: null,
    };
    if (c) f.valor = c.beneficio === 'desconto_fixo' ? centavosParaTexto(c.beneficioValor) : (c.beneficio === 'gratuidade' ? '' : String(c.beneficioValor));
    return f;
  }

  const opcoes = (lista, atual, rotulo) => lista.map(v => '<option value="' + esc(v) + '"' + (v === atual ? ' selected' : '') + '>' + esc(rotulo(v)) + '</option>').join('');
  const campoHtml = (rotulo, conteudo, extra) => '<label class="mp-lim-campo' + (extra ? ' ' + extra : '') + '"><span>' + esc(rotulo) + '</span>' + conteudo + '</label>';

  function desenharForm() {
    const f = tela.f, trava = f.oab ? ' disabled' : '';
    const ehGratis = f.beneficio === 'gratuidade';
    const unidade = { desconto_percentual: T('valor_percentual'), desconto_fixo: T('valor_fixo'), espaco_extra: T('valor_espaco') }[f.beneficio];
    const corpo = erroHtml(f.erro) + faixa()
      + (f.codigo ? '<div class="mp-cv-codigo"><div><span>' + esc(T('codigo')) + '</span><b data-cv-codigo>' + esc(f.codigo) + '</b></div>'
        + '<div><button type="button" class="mp-convenio-link" data-cv-outro-codigo' + (ocupado ? ' disabled' : '') + '>' + esc(T('gerar_outro')) + '</button><small>' + esc(T('gerar_outro_nota')) + '</small></div></div>' : '')
      + (f.oab ? '<div class="mp-lim-nota">' + esc(T('nota_oab')) + '</div>' : '')
      + '<div class="mp-lim-duas">' + campoHtml(T('campo_nome'), '<input type="text" maxlength="120" data-cv-f="nome" value="' + esc(f.nome) + '">')
      + campoHtml(T('campo_instituicao'), '<input type="text" maxlength="120" data-cv-f="instituicao" value="' + esc(f.instituicao) + '">') + '</div>'
      + '<div class="mp-lim-duas">' + campoHtml(T('campo_abrangencia'), '<select data-cv-f="abrangencia" data-cv-refaz' + trava + '>' + opcoes(ABRANGENCIAS, f.abrangencia, v => T('abr_op_' + v)) + '</select>')
      + (f.abrangencia === 'uf' ? campoHtml(T('campo_uf'), '<select data-cv-f="uf">' + opcoes(UFS, f.uf, v => v) + '</select>') : '<div></div>') + '</div>'
      + '<div class="mp-lim-duas">' + campoHtml(T('campo_beneficio'), '<select data-cv-f="beneficio" data-cv-refaz' + trava + '>' + opcoes(BENEFICIOS, f.beneficio, v => T('ben_op_' + v)) + '</select>')
      + (ehGratis ? '<div></div>' : campoHtml(unidade, '<input type="text" inputmode="decimal" data-cv-f="valor" value="' + esc(f.valor) + '"' + (f.oab ? ' disabled' : '') + '>')) + '</div>'
      + campoHtml(T('campo_selo'), '<input type="text" maxlength="40" data-cv-f="textoSelo" value="' + esc(f.textoSelo) + '"><small class="mp-cv-contador" data-cv-contador></small>')
      + '<div class="mp-lim-duas">' + campoHtml(T('campo_de'), '<input type="date" data-cv-f="validoDe" value="' + esc(f.validoDe) + '">')
      + campoHtml(T('campo_ate'), '<input type="date" data-cv-f="validoAte" value="' + esc(f.validoAte) + '">') + '</div>'
      + campoHtml(T('campo_limite'), '<input type="number" min="1" step="1" data-cv-f="limite" placeholder="' + esc(T('limite_dica')) + '" value="' + esc(f.limite) + '">');
    return caixa(T(f.id ? 'titulo_editar' : 'titulo_novo'), corpo,
      btnCancelar() + '<button type="button" class="mp-lim-ok" data-cv-salvar' + (ocupado ? ' disabled' : '') + '>' + esc(T('salvar')) + '</button>', true);
  }

  // Lê os campos da tela para o estado (sem redesenhar).
  function lerForm() {
    if (!tela || tela.nome !== 'form') return;
    host.querySelectorAll('[data-cv-f]').forEach(el => { tela.f[el.dataset.cvF] = el.value; });
  }

  function atualizarForm() {
    const c = host.querySelector('[data-cv-contador]'), el = host.querySelector('[data-cv-f="textoSelo"]');
    if (c && el) c.textContent = el.value.length + '/40';
  }

  // Valor do benefício no formato do servidor: percentual e GB inteiros, R$ em centavos. null = inválido.
  function valorEmNumero(f) {
    if (f.beneficio === 'gratuidade') return 0;
    const bruto = String(f.valor).trim();
    if (f.beneficio === 'desconto_fixo') {
      if (!/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/.test(bruto)) return null;
      const cent = Math.round(parseFloat(bruto.replace(/\./g, '').replace(',', '.')) * 100);
      return cent > 0 ? cent : null;
    }
    if (!/^\d+$/.test(bruto)) return null;
    const n = parseInt(bruto, 10);
    if (f.beneficio === 'desconto_percentual') return n >= 1 && n <= 100 ? n : null;
    return n >= 1 ? n : null;
  }

  function corpoDoForm(f, gerarOutro) {
    const valor = valorEmNumero(f);
    if (valor === null) return null;
    return {
      id: f.id, nome: f.nome.trim(), instituicao: f.instituicao.trim(), abrangencia: f.abrangencia, uf: f.abrangencia === 'uf' ? f.uf : null,
      beneficio: f.beneficio, beneficioValor: valor, textoSelo: f.textoSelo.trim(), validoDe: f.validoDe, validoAte: f.validoAte || null,
      limiteDeUsos: String(f.limite).trim() === '' ? null : parseInt(f.limite, 10), gerarOutroCodigo: !!gerarOutro,
    };
  }

  function gravarForm(gerarOutro) {
    lerForm();
    const f = tela.f, corpo = corpoDoForm(f, gerarOutro);
    if (!corpo) { f.erro = T('valor_invalido'); msg = null; return desenhar(); }
    f.erro = null; msg = null;
    postar(ctx.salvar, corpo, d => {
      if (gerarOutro) {
        const c = L.convenios.find(x => x.id === d.id) || L.convenios.find(x => x.id === f.id);
        if (c) f.codigo = c.codigoConvite;
        if (d.id) f.id = d.id;
        return desenhar();
      }
      tela = { nome: 'lista' }; desenhar();
    }, e => { f.erro = e; desenhar(); });
    desenhar();
  }

  // ---------- Ligar e desligar ----------
  function ligarDeNovo(id) {
    msg = null;
    postar(ctx.ligar.replace('/0/', '/' + id + '/'), { ligado: true }, () => { tela = { nome: 'lista' }; desenhar(); },
      e => { tela = { nome: 'lista', erro: e }; desenhar(); });
    desenhar();
  }

  function abrirDesligar(c) {
    msg = null;
    const real = !!c.beneficioReal, minimo = real ? L.prazoMinimo : hoje();
    tela = { nome: 'desligar', c, minimo, data: real ? L.prazoMinimo : hoje(), motivo: '', erro: null };
    desenhar();
  }

  function desenharDesligar() {
    const t = tela, c = t.c, inst = L.instituicoes && L.instituicoes[c.instituicaoChave];
    const todos = inst && inst.convenios > 1;
    const corpo = erroHtml(t.erro)
      + '<b class="mp-lim-conta">' + esc(c.nome) + ' <span class="mp-cv-sub">' + esc(c.instituicao) + '</span></b>'
      + (c.beneficioReal ? '<div class="mp-lim-nota">' + esc(T('nota_prazo', { data: dataBr(L.prazoMinimo) })) + '</div>' : '')
      + campoHtml(T('desligar_data'), '<input type="date" data-cv-d="data" min="' + esc(t.minimo) + '" value="' + esc(t.data) + '">')
      + campoHtml(T('desligar_motivo'), '<input type="text" maxlength="200" data-cv-d="motivo" placeholder="' + esc(T('desligar_motivo_dica')) + '" value="' + esc(t.motivo) + '">')
      + '<div class="mp-cv-previa" data-cv-previa></div>'
      + (todos ? '<div class="mp-cv-previa mp-cv-previa-inst" data-cv-previa-inst></div>' : '');
    return caixa(T('desligar_titulo'), corpo,
      btnCancelar(T('desligar_nao'))
      + (todos ? '<button type="button" class="mp-cv-btn-sec" data-cv-desligar-todos' + (ocupado ? ' disabled' : '') + '>' + esc(T('desligar_todos', { instituicao: inst.nome || c.instituicao })) + '</button>' : '')
      + '<button type="button" class="mp-lim-ok" data-cv-desligar' + (ocupado ? ' disabled' : '') + '>' + esc(T('desligar_um')) + '</button>', true);
  }

  const dataValida = t => !!t.data && t.data >= t.minimo;

  function previaTexto(contas, emails, data) {
    const quando = data === hoje() ? T('previa_agora') : T('previa_termina', { data: dataBr(data) });
    return T('previa', { contas, quando, emails });
  }

  function atualizarPrevia() {
    const t = tela, c = t.c, inst = L.instituicoes && L.instituicoes[c.instituicaoChave];
    const p = host.querySelector('[data-cv-previa]'), pi = host.querySelector('[data-cv-previa-inst]');
    const ok = dataValida(t);
    if (p) p.textContent = ok ? previaTexto(c.contas, c.emails, t.data) : T('data_invalida', { data: dataBr(t.minimo) });
    if (pi && inst) pi.textContent = ok ? T('previa_instituicao', { instituicao: inst.nome || c.instituicao, n: inst.convenios }) + ' ' + previaTexto(inst.contas, inst.emails, t.data) : '';
    host.querySelectorAll('[data-cv-desligar], [data-cv-desligar-todos]').forEach(b => { b.disabled = !ok || ocupado; });
  }

  function confirmarDesligar(todos) {
    const t = tela; if (!dataValida(t)) return;
    const corpo = { encerraEm: t.data };
    if (t.motivo.trim()) corpo.motivo = t.motivo.trim();
    t.erro = null;
    const url = todos ? ctx.instituicao.replace('__chave__', encodeURIComponent(t.c.instituicaoChave)) : ctx.ligar.replace('/0/', '/' + t.c.id + '/');
    if (!todos) corpo.ligado = false;
    postar(url, corpo, () => { tela = { nome: 'lista' }; desenhar(); }, e => { t.erro = e; desenhar(); });
    desenhar();
  }

  // ---------- Eventos ----------
  function fechar() {
    if (host) host.innerHTML = '';
    tela = null; msg = null;
  }

  const buscar = id => L && L.convenios.find(c => c.id === +id);

  function aoClicar(e) {
    if (!tela) return;
    const alvo = e.target;
    if (alvo.matches('[data-cv-fundo]') || alvo.closest('[data-cv-fechar]')) return fechar();
    if (alvo.closest('[data-cv-voltar]')) { msg = null; if (L) { tela = { nome: 'lista' }; return desenhar(); } return fechar(); }
    if (alvo.closest('[data-cv-novo]')) { msg = null; tela = { nome: 'form', f: novoForm(null) }; return desenhar(); }
    const ed = alvo.closest('[data-cv-editar]');
    if (ed) { const c = buscar(ed.dataset.cvEditar); if (c) { msg = null; tela = { nome: 'form', f: novoForm(c) }; desenhar(); } return; }
    if (alvo.closest('[data-cv-por-estado]')) { fechar(); if (ctx.irParaContas) ctx.irParaContas(); return; }
    const chave = alvo.closest('[data-cv-chave]');
    if (chave) { const c = buscar(chave.dataset.cvChave); if (!c || ocupado) return; return c.ativo ? abrirDesligar(c) : ligarDeNovo(c.id); }
    const fim = alvo.closest('[data-cv-cancelar-fim]');
    if (fim) return ligarDeNovo(+fim.dataset.cvCancelarFim);
    if (alvo.closest('[data-cv-salvar]')) return gravarForm(false);
    if (alvo.closest('[data-cv-outro-codigo]')) return gravarForm(true);
    if (alvo.closest('[data-cv-desligar-todos]')) return confirmarDesligar(true);
    if (alvo.closest('[data-cv-desligar]')) return confirmarDesligar(false);
  }

  function aoMudar(e) {
    if (!tela) return;
    const alvo = e.target;
    if (tela.nome === 'form' && alvo.dataset.cvF) {
      lerForm(); tela.f.erro = null;
      if (alvo.hasAttribute('data-cv-refaz') && e.type === 'change') return desenhar();
      return atualizarForm();
    }
    if (tela.nome === 'desligar' && alvo.dataset.cvD) { tela[alvo.dataset.cvD] = alvo.value; tela.erro = null; atualizarPrevia(); }
  }

  function aoTecla(e) { if (e.key === 'Escape' && tela) fechar(); }

  window.BJConvenios = {
    abrir(contexto) {
      ctx = contexto;
      host = document.getElementById('mp-modal');
      if (!host) return;
      if (!ligado) {
        host.addEventListener('click', aoClicar);
        host.addEventListener('input', aoMudar);
        host.addEventListener('change', aoMudar);
        document.addEventListener('keydown', aoTecla);
        ligado = true;
      }
      msg = null; L = null; ocupado = false;
      tela = { nome: 'carga' }; desenhar();
      fetchJson(ctx.lista)
        .then(d => { L = d; tela = { nome: 'lista' }; desenhar(); })
        .catch(() => { tela = { nome: 'carga', erro: T('erro_carregar') }; desenhar(); });
    },
  };
})();
