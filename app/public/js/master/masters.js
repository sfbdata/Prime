/* BlueJus · Painel do Master, aba Permissões: bloco "Masters do sistema"
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, linhas 317-336, 624 e 641-652).
   - select "Promover a Master": mostra "e-mail · OAB" do escolhido e a mensagem de erro sem escolha;
   - confirmação interna (promover e remover Master) no lugar do window.confirm. A mesma confirmação
     serve ao painel.js (suspender, reativar e excluir conta, esta com a palavra EXCLUIR) por window.BJMasterConf.abrir(op).
   - Textos no catálogo (master.js.masters.*, entregue pelo painel com catalogo_js); o termo Master vem de termos.master.
   Nenhuma decisão mora só aqui: o servidor revalida tudo (CSRF, Master primário, regras da spec perfis-1.2-masters). */
(() => {
  'use strict';
  const raiz = document.getElementById('master-painel');
  if (!raiz) return;

  const COR_ROXO = '#7a3e8f';
  const COR_PERIGO = '#a3232b';
  const qs = (sel, el) => (el || raiz).querySelector(sel);
  const T = (chave, params) => window.BJ_T.t(chave, Object.assign({ papel: window.BJ_T.t('termos.master') }, params));

  /* ---------- confirmação ---------- */
  const conf = qs('[data-mp-conf]');
  let aoConfirmar = null;
  // Palavra que a pessoa precisa digitar para liberar o botão (excluir conta: EXCLUIR). Nula nas demais.
  let palavraExigida = null;
  const campo = conf ? qs('[data-mp-conf-campo]', conf) : null;
  const entrada = conf ? qs('[data-mp-conf-palavra]', conf) : null;
  // Escolha obrigatória (op.escolha = { rotulo, vazio, opcoes: [[valor, texto]] }): o botão só libera com uma opção escolhida.
  const escolha = conf ? qs('[data-mp-conf-escolha]', conf) : null;
  const selecao = conf ? qs('[data-mp-conf-escolha-select]', conf) : null;
  let escolhaExigida = false;
  // Campo livre (op.entrada = { rotulo, valor, tipo }): o mesmo campo da palavra, já preenchido; o botão libera com texto.
  let entradaLivre = false;

  // Pergunta em andamento por perguntar(): fechar sem confirmar (Cancelar, Esc, clique fora) resolve com false.
  let respostaPendente = null;
  const fecharConf = () => {
    if (!conf) return;
    conf.hidden = true; aoConfirmar = null;
    const pendente = respostaPendente; respostaPendente = null;
    if (pendente) pendente(false);
  };
  const abrirConf = op => {
    if (!conf) return;
    // Uma pergunta (perguntar) ainda aberta é respondida com false antes de outra janela ocupar o lugar.
    if (respostaPendente) { const anterior = respostaPendente; respostaPendente = null; anterior(false); }
    qs('[data-mp-conf-titulo]', conf).textContent = op.titulo;
    qs('[data-mp-conf-icone]', conf).className = 'bi ' + op.icone;
    qs('[data-mp-conf-texto]', conf).textContent = op.texto;
    const ok = qs('[data-mp-conf-ok]', conf);
    ok.textContent = op.botao;
    // Rótulo do botão de fechar: o padrão do HTML, ou o da ação (ex.: "Voltar" quando o botão de confirmar já diz "Cancelar o envio").
    const fechar = qs('[data-mp-conf-cancelar]', conf);
    if (fechar.dataset.padrao === undefined) fechar.dataset.padrao = fechar.textContent;
    fechar.textContent = op.cancelar || fechar.dataset.padrao;
    palavraExigida = op.palavra || null;
    entradaLivre = !palavraExigida && !!op.entrada && !!campo && !!entrada;
    if (campo && entrada) {
      campo.hidden = !palavraExigida && !entradaLivre;
      entrada.type = entradaLivre && op.entrada.tipo === 'email' ? 'email' : 'text';
      entrada.value = entradaLivre ? String(op.entrada.valor || '') : '';
      qs('[data-mp-conf-rotulo]', conf).textContent = palavraExigida ? T('master.js.masters.digite', { palavra: palavraExigida }) : entradaLivre ? String(op.entrada.rotulo || '') : '';
    }
    escolhaExigida = !!(op.escolha && escolha && selecao);
    if (escolha && selecao) {
      escolha.hidden = !escolhaExigida;
      selecao.replaceChildren();
      if (escolhaExigida) {
        qs('[data-mp-conf-escolha-rotulo]', conf).textContent = op.escolha.rotulo || '';
        selecao.append(new Option(op.escolha.vazio || '', ''));
        (op.escolha.opcoes || []).forEach(([valor, texto]) => selecao.append(new Option(texto, valor)));
      }
    }
    // A ação do painel não recarrega a página: destrava para a próxima confirmação. Com palavra, só destrava quando bater;
    // com escolha, só quando houver uma opção escolhida.
    ok.disabled = !!palavraExigida || escolhaExigida || (entradaLivre && entrada.value.trim() === '');
    conf.style.setProperty('--cor', op.cor);
    aoConfirmar = op.aoConfirmar;
    conf.hidden = false;
    ((palavraExigida || entradaLivre) && entrada ? entrada : (escolhaExigida ? selecao : ok)).focus();
  };

  if (conf) {
    conf.addEventListener('click', e => { if (e.target === conf) fecharConf(); });
    qs('[data-mp-conf-cancelar]', conf).addEventListener('click', fecharConf);
    // Um envio só: o botão trava no primeiro clique (o servidor recusaria o segundo, mas o flash confundiria).
    qs('[data-mp-conf-ok]', conf).addEventListener('click', e => {
      const acao = aoConfirmar; if (!acao) return;
      const digitado = entrada ? entrada.value.trim() : '';
      if (palavraExigida && digitado !== palavraExigida) return;
      if (escolhaExigida && !selecao.value) return;
      if (entradaLivre && digitado === '') return;
      aoConfirmar = null; e.currentTarget.disabled = true; acao(escolhaExigida ? selecao.value : digitado);
    });
    if (entrada) entrada.addEventListener('input', () => {
      if (palavraExigida) qs('[data-mp-conf-ok]', conf).disabled = entrada.value.trim() !== palavraExigida;
      else if (entradaLivre) qs('[data-mp-conf-ok]', conf).disabled = entrada.value.trim() === '';
    });
    if (selecao) selecao.addEventListener('change', () => { if (escolhaExigida) qs('[data-mp-conf-ok]', conf).disabled = !selecao.value; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !conf.hidden) fecharConf(); });
  }

  // Confirmação compartilhada com o painel.js (menu da conta: suspender, reativar e excluir com a palavra EXCLUIR).
  // Versão com Promise (campanhas de e-mail): resolve true só se confirmar (com `escolha`, o valor escolhido; com `entrada`,
  // o texto digitado); Cancelar, Esc e clique fora resolvem false.
  const perguntar = op => new Promise(resolver => {
    if (!conf) { resolver(false); return; }
    abrirConf(Object.assign({ icone: 'bi-question-circle', cor: COR_ROXO }, op, {
      aoConfirmar: valor => { respostaPendente = null; fecharConf(); resolver(op.escolha || op.entrada ? valor : true); },
    }));
    respostaPendente = resolver;
  });
  window.BJMasterConf = { abrir: abrirConf, fechar: fecharConf, perguntar };

  /* ---------- promover a Master (só no HTML do Master primário) ---------- */
  const form = qs('[data-mp-form-promover]');
  if (form) {
    const sel = qs('[data-mp-promover-select]', form);
    const info = qs('[data-mp-promover-info]', form);
    const msg = qs('[data-mp-promover-msg]', form);
    const escolhido = () => sel.options[sel.selectedIndex];

    sel.addEventListener('change', () => {
      info.textContent = sel.value ? escolhido().getAttribute('data-info') : info.getAttribute('data-padrao');
      msg.textContent = msg.getAttribute('data-padrao');
      msg.classList.remove('is-erro');
    });

    qs('[data-mp-promover]', form).addEventListener('click', () => {
      if (!sel.value) {
        msg.textContent = T('master.js.masters.escolha');
        msg.classList.add('is-erro');
        return;
      }
      abrirConf({
        titulo: T('master.js.masters.promover_titulo'), icone: 'bi-stars', cor: COR_ROXO, botao: T('master.js.masters.promover_botao'),
        texto: T('master.js.masters.promover_texto', { nome: escolhido().getAttribute('data-nome') }),
        aoConfirmar: () => form.submit()
      });
    });
  }

  /* ---------- busca (pedido M3 do Farlei, 24/11): filtra no navegador, a lista inteira já está na página ---------- */
  // Masters: esconde as linhas que não casam com nome, e-mail ou OAB; sem nenhuma, mostra "Nenhum Master encontrado.".
  const buscaMasters = qs('[data-mp-masters-busca]');
  if (buscaMasters) {
    buscaMasters.addEventListener('input', () => {
      const t = window.BJBusca.termo(buscaMasters.value);
      let algum = false;
      raiz.querySelectorAll('[data-mp-master-busca]').forEach(lin => {
        lin.hidden = !window.BJBusca.casa(lin.getAttribute('data-mp-master-busca'), t);
        algum = algum || !lin.hidden;
      });
      const nenhum = qs('[data-mp-masters-nenhum]');
      if (nenhum) nenhum.hidden = algum || !raiz.querySelector('[data-mp-master-busca]');
    });
  }
  // Promover: o select guarda todos os usuários; a busca esconde as opções que não casam (o escolhido fica sempre).
  const buscaPromover = form && qs('[data-mp-promover-busca]', form);
  if (buscaPromover) {
    const sel = qs('[data-mp-promover-select]', form);
    buscaPromover.addEventListener('input', () => {
      const t = window.BJBusca.termo(buscaPromover.value);
      Array.from(sel.options).forEach(op => {
        // hidden esconde no Chrome e no Firefox; o Safari ignora hidden em <option>, então ela também fica desabilitada.
        if (op.value) op.hidden = op.disabled = op.selected ? false : !window.BJBusca.casa(op.getAttribute('data-busca'), t);
      });
    });
  }

  /* ---------- remover Master secundário (só no HTML do Master primário) ---------- */
  raiz.querySelectorAll('[data-mp-remover-master]').forEach(btn => btn.addEventListener('click', () => {
    abrirConf({
      titulo: T('master.js.masters.remover'), icone: 'bi-person-dash', cor: COR_PERIGO, botao: T('master.js.masters.remover'),
      texto: T('master.js.masters.remover_texto', { nome: btn.getAttribute('data-nome') }),
      aoConfirmar: () => btn.closest('form').submit()
    });
  }));
})();
