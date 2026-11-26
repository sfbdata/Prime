/* BlueJus · Painel do Master, bloco "Revisão de OAB"
   (desenho docs/design/redesign-31-10-26/05 - Perfis 1.2 - VS 31.10.26.dc.html: seção roab no SECS, marcação embutida
   em Usuários e OABs e sozinha em secRoabSo, lógica roabVals).
   - O painel.js chama BJRevisaoOab.montar(raiz, ctx) ao abrir a seção Revisão de OAB e ao montar Usuários e OABs (o
     mesmo bloco, abaixo da nota da contagem normalizada). ctx traz as URLs e o token (csrf master_revisao_oab).
   - A lista, as contagens e a validação automática vêm do GET /master/revisao-oab; cada ação é um POST JSON com
     X-CSRF-Token e, dando certo, a lista é lida de novo. Erro do servidor ({erro}) aparece na caixa de mensagem.
   - Diariamente e Semanalmente ficam desabilitadas (o servidor recusa sem a consulta ao CNA).
   - Textos no catálogo (master.js.revisao_oab.*, entregue pelo painel com catalogo_js), lidos por BJ_T.t na hora de usar.
   - Nome, e-mail, escritório e nome no CNA são dado de usuário: sempre passam por esc(). */
(() => {
  'use strict';
  const PREFIXO = 'master.js.revisao_oab.';
  const T = (chave, params) => window.BJ_T.t(PREFIXO + chave, params);
  // Aspas pelo código (\x22 e \x27), como no painel.js: o detector de texto fixo (SemTextoFixoNosExtraidos) lê literais.
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' };
  const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, m => ESC[m]);
  const num = n => window.BJFormato.numero(+n || 0);
  const dataHora = iso => window.BJFormato.dataHora(iso);
  const norm = x => String(x || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

  // Status do servidor => grupo da aba e pílula do desenho (STI). Status sem mapeamento cai em Pendente.
  const GRUPO = { confirmada: 'conf', divergente: 'div', nao_verificada: 'nao' };
  const PILULA = { conf: 'bi-patch-check-fill', div: 'bi-exclamation-triangle-fill', nao: 'bi-clock', pend: 'bi-hourglass-split' };
  const ABAS = ['pend', 'div', 'nao', 'conf', 'todas'];
  const FREQS = ['cadastro', 'diaria', 'semanal'];
  // Motivo da Divergente (ConsultarRevisaoOabUseCase::motivo): o "não encontrada no CNA" fica registrado com a data.
  const MOTIVOS = ['nao_encontrada', 'nome', 'formato'];

  let raiz = null;
  let ctx = null;
  let R = null; // resposta do GET
  let falhou = false;
  let aba = 'pend';
  let busca = '';
  let msg = ''; // mensagem da rodada (Validar agora): fica até a próxima rodada, como no desenho
  let erro = ''; // recusa do servidor: sai na próxima ação que der certo
  let ocupado = false;

  const grupoDe = l => GRUPO[l.status] || 'pend';
  const naAba = l => aba === 'todas' || (aba === 'pend' ? l.status !== 'confirmada' : grupoDe(l) === aba);
  const naBusca = l => !busca || norm(l.nome + ' ' + l.email + ' ' + l.inscricao + ' ' + l.numero).includes(norm(busca));

  const pedir = (url, opcoes) => fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opcoes))
    .then(r => r.json().catch(() => null).then(j => {
      const json = !r.redirected && j !== null && typeof j === 'object';
      if (json && r.ok) return j;
      return Promise.reject(new Error(json && j.erro ? j.erro : T('erro')));
    }));

  function carregar() {
    return pedir(ctx.url)
      .then(j => { R = j; falhou = false; })
      .catch(() => { falhou = true; })
      .finally(desenhar);
  }

  function postar(url, corpo, aoDarCerto) {
    if (ocupado) return;
    ocupado = true;
    pedir(url, { method: 'POST', body: JSON.stringify(corpo), headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': ctx.token } })
      .then(j => { erro = ''; if (aoDarCerto) aoDarCerto(j); return carregar(); })
      .catch(e => { erro = e.message || T('erro'); desenhar(); })
      .finally(() => { ocupado = false; });
  }

  const urlDaInscricao = (modelo, id) => modelo.replace(/\/0\//, '/' + encodeURIComponent(id) + '/');

  /* ---------- marcação ---------- */
  function esqueleto() {
    const cab = ['nome', 'escritorio', 'oab', 'nome_cna', 'status', 'verificada_em'].map(k => '<span>' + T('col.' + k) + '</span>').join('')
      + '<span class="roab-dir">' + T('col.acoes') + '</span>';
    raiz.innerHTML = '<div class="roab">'
      + '<div class="roab-topo"><div class="roab-topo-txt"><div class="roab-titulo">' + T('titulo') + '</div><div class="roab-sub">' + T('subtitulo') + '</div></div>'
      + '<span class="roab-selo"><i class="bi bi-people-fill"></i><span data-roab-total></span></span></div>'
      + '<div class="roab-caixa roab-auto"><button type="button" class="roab-chave" data-roab-auto><span class="roab-chave-trilho"><span></span></span><span data-roab-auto-txt></span></button>'
      + '<select class="roab-freq" data-roab-freq aria-label="' + esc(T('frequencia')) + '">'
      + FREQS.map(f => '<option value="' + f + '"' + (f === 'cadastro' ? '' : ' disabled title="' + esc(T('freq_sem_cna')) + '"') + '>' + T('freq.' + f) + '</option>').join('')
      + '</select><span class="roab-ajuda">' + T('ajuda') + ' <span data-roab-ult></span></span>'
      + '<button type="button" class="roab-rodar" data-roab-rodar><i class="bi bi-arrow-repeat"></i>' + T('validar_agora') + '</button></div>'
      + '<div class="roab-msg" data-roab-msg hidden></div>'
      + '<div class="roab-caixa roab-filtros"><span class="roab-abas" data-roab-abas></span><span class="roab-espaco"></span>'
      + '<label class="roab-busca"><i class="bi bi-search"></i><input type="text" data-roab-busca placeholder="' + esc(T('busca')) + '" aria-label="' + esc(T('busca')) + '"></label></div>'
      + '<div class="roab-tabela"><div class="roab-larga"><div class="roab-grade roab-cab">' + cab + '</div>'
      + '<div class="roab-vazio" data-roab-vazio hidden>' + T('vazio') + '</div><div data-roab-linhas></div></div></div>'
      + '</div>';
    raiz.querySelector('[data-roab-busca]').value = busca;
  }

  function linha(l) {
    const g = grupoDe(l);
    const em = l.verificadaEm ? dataHora(l.verificadaEm) : T('sem_registro');
    return '<div class="roab-grade roab-linha"><b title="' + esc(l.nome) + '">' + esc(l.nome) + '</b>'
      + '<span class="roab-esc" title="' + esc(l.email) + '">' + esc(l.escritorio) + '</span>'
      + '<span class="roab-oab"><b>' + esc(l.inscricao) + '</b>' + '<a class="roab-site" href="https://cna.oab.org.br/" target="_blank" rel="noopener noreferrer" title="' + esc(T('conferir_site', { inscricao: l.inscricao })) + '" aria-label="' + esc(T('conferir_site_rotulo')) + '"><i class="bi bi-box-arrow-up-right"></i></a>' + (l.principal ? '<span class="roab-principal"><i class="bi bi-star-fill"></i>' + T('principal') + '</span>' : '') + '</span>'
      + '<span class="roab-cna' + (l.nomeCna ? '' : ' roab-cna-sem') + '">' + (l.nomeCna ? esc(l.nomeCna) : T('nao_informado')) + '</span>'
      + '<span><span class="roab-st roab-st-' + g + '"><i class="bi ' + PILULA[g] + '"></i>' + T('status.' + g) + '</span>'
      + (MOTIVOS.includes(l.motivo) ? '<small class="roab-motivo">' + T('motivo.' + l.motivo) + '</small>' : '') + '</span>'
      + '<span class="roab-em">' + esc(em) + '</span>'
      + '<span class="roab-acoes"><button type="button" class="roab-confirmar" data-roab-confirmar="' + l.id + '" title="' + esc(T('confirmar')) + '" aria-label="' + esc(T('confirmar')) + '"><i class="bi bi-check-lg"></i></button>'
      + '<button type="button" class="roab-reverif" data-roab-reverif="' + l.id + '" title="' + esc(T('verificar')) + '" aria-label="' + esc(T('verificar')) + '"><i class="bi bi-arrow-repeat"></i></button></span></div>';
  }

  function desenharLinhas() {
    const V = R ? R.linhas.filter(l => naAba(l) && naBusca(l)) : [];
    raiz.querySelector('[data-roab-linhas]').innerHTML = V.map(linha).join('');
    raiz.querySelector('[data-roab-vazio]').hidden = V.length > 0;
  }

  function desenhar() {
    if (!raiz) return;
    if (!raiz.querySelector('.roab')) esqueleto();
    const C = R ? R.contagens : { pend: 0, div: 0, nao: 0, conf: 0, todas: 0 };
    const A = R ? R.auto : { ligada: false, frequencia: 'cadastro', ultimaRodada: null };
    raiz.querySelector('[data-roab-total]').textContent = T(C.todas === 1 ? 'total_um' : 'total', { n: num(C.todas) });
    raiz.querySelector('[data-roab-auto]').classList.toggle('on', !!A.ligada);
    raiz.querySelector('[data-roab-auto-txt]').textContent = A.ligada ? T('auto_ligada') : T('auto_desligada');
    raiz.querySelector('[data-roab-freq]').value = FREQS.includes(A.frequencia) ? A.frequencia : 'cadastro';
    raiz.querySelector('[data-roab-ult]').textContent = A.ultimaRodada ? T('ultima_rodada', { quando: dataHora(A.ultimaRodada) }) : T('ainda_nao_rodou');
    const elMsg = raiz.querySelector('[data-roab-msg]');
    const texto = falhou ? T('erro_carga') : erro || msg;
    elMsg.textContent = texto;
    elMsg.hidden = !texto;
    raiz.querySelector('[data-roab-abas]').innerHTML = ABAS.map(k => '<button type="button" class="roab-aba' + (aba === k ? ' on' : '') + '" data-roab-aba="' + k + '">'
      + T('aba.' + k) + ' <span>' + num(C[k]) + '</span></button>').join('');
    desenharLinhas();
  }

  /* ---------- ações ---------- */
  const linhaPorId = id => (R ? R.linhas.find(l => String(l.id) === String(id)) : null);

  function confirmar(l) {
    postar(urlDaInscricao(ctx.urlConfirmar, l.id), { numero: l.numero, uf: l.uf });
  }

  /* Verificar de novo (desenho: verificar): janela na página com o campo "Nome no CNA", já com o nome do CNA ou o do
     usuário, no componente mp-conf do painel (sem o prompt do navegador; pedido do dono 26/11). Cancelar, Esc ou
     clique fora não fazem nada (decisão do Coordenador 31/10). Salvar com o campo vazio registra a inscrição como não
     encontrada no CNA (Divergente, com a data). Nome igual confirma; diferente diverge. A janela é uma só, criada na
     primeira vez dentro do #master-painel (o painel troca o miolo da seção a cada abertura). */
  let janela = null;
  let emVerificacao = null;

  function fecharJanela() {
    if (!janela) return;
    janela.hidden = true;
    emVerificacao = null;
  }

  function salvarJanela() {
    const l = emVerificacao;
    if (!l) return;
    const nomeCna = janela.querySelector('[data-roab-janela-nome]').value.trim();
    fecharJanela();
    postar(urlDaInscricao(ctx.urlConferir, l.id), { numero: l.numero, uf: l.uf, nomeCna });
  }

  function criarJanela() {
    const el = document.createElement('div');
    el.className = 'mp-conf-fundo';
    el.hidden = true;
    el.setAttribute('data-roab-janela', '');
    el.innerHTML = '<div class="mp-conf" role="dialog" aria-modal="true" aria-labelledby="roabJanelaTitulo">'
      + '<div class="mp-conf-cab"><i class="bi bi-arrow-repeat"></i><span id="roabJanelaTitulo">' + T('janela.titulo') + '</span></div>'
      + '<div class="mp-conf-texto" data-roab-janela-texto></div>'
      + '<div class="mp-conf-campo"><label for="roabJanelaNome">' + T('janela.campo') + '</label>'
      + '<input id="roabJanelaNome" class="roab-janela-nome" type="text" maxlength="255" autocomplete="off" spellcheck="false" data-roab-janela-nome></div>'
      + '<div class="mp-conf-rodape"><button type="button" class="mp-conf-cancelar" data-roab-janela-cancelar>' + T('janela.cancelar') + '</button>'
      + '<button type="button" class="mp-conf-ok" data-roab-janela-salvar>' + T('janela.salvar') + '</button></div></div>';
    (document.getElementById('master-painel') || document.body).appendChild(el);
    el.addEventListener('click', e => { if (e.target === el) fecharJanela(); });
    el.querySelector('[data-roab-janela-cancelar]').addEventListener('click', fecharJanela);
    el.querySelector('[data-roab-janela-salvar]').addEventListener('click', salvarJanela);
    el.querySelector('[data-roab-janela-nome]').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); salvarJanela(); } });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !el.hidden) fecharJanela(); });

    return el;
  }

  function verificar(l) {
    if (!janela) janela = criarJanela();
    emVerificacao = l;
    janela.querySelector('[data-roab-janela-texto]').textContent = T('janela.texto', { inscricao: l.inscricao });
    const campo = janela.querySelector('[data-roab-janela-nome]');
    campo.value = l.nomeCna || l.nome;
    janela.hidden = false;
    campo.focus();
    campo.select();
  }

  function alterarAuto(ligada, frequencia) {
    postar(ctx.urlAutomatica, { ligada, frequencia });
  }

  function ouvir() {
    raiz.addEventListener('click', e => {
      const b = e.target.closest('button');
      if (!b || !raiz.contains(b)) return;
      if (b.dataset.roabAba) { aba = b.dataset.roabAba; desenhar(); return; }
      if (b.hasAttribute('data-roab-auto')) { const A = R ? R.auto : { ligada: false, frequencia: 'cadastro' }; alterarAuto(!A.ligada, A.frequencia); return; }
      if (b.hasAttribute('data-roab-rodar')) { postar(ctx.urlValidarAgora, {}, j => { msg = j.mensagem || ''; }); return; }
      const lc = b.dataset.roabConfirmar && linhaPorId(b.dataset.roabConfirmar);
      if (lc) { confirmar(lc); return; }
      const lv = b.dataset.roabReverif && linhaPorId(b.dataset.roabReverif);
      if (lv) verificar(lv);
    });
    raiz.addEventListener('change', e => {
      if (!e.target.matches('[data-roab-freq]')) return;
      alterarAuto(R ? !!R.auto.ligada : false, e.target.value);
    });
    raiz.addEventListener('input', e => {
      if (!e.target.matches('[data-roab-busca]')) return;
      busca = e.target.value;
      desenharLinhas();
    });
  }

  window.BJRevisaoOab = {
    // raiz: elemento novo a cada montagem da seção (o painel troca o innerHTML); a lista é lida de novo.
    montar(el, contexto) {
      raiz = el;
      ctx = contexto;
      falhou = false;
      ouvir();
      desenhar();
      carregar();
    },
  };
})();
