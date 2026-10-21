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

  const fecharConf = () => { if (!conf) return; conf.hidden = true; aoConfirmar = null; };
  const abrirConf = op => {
    if (!conf) return;
    qs('[data-mp-conf-titulo]', conf).textContent = op.titulo;
    qs('[data-mp-conf-icone]', conf).className = 'bi ' + op.icone;
    qs('[data-mp-conf-texto]', conf).textContent = op.texto;
    const ok = qs('[data-mp-conf-ok]', conf);
    ok.textContent = op.botao;
    palavraExigida = op.palavra || null;
    if (campo && entrada) {
      campo.hidden = !palavraExigida;
      entrada.value = '';
      qs('[data-mp-conf-rotulo]', conf).textContent = palavraExigida ? T('master.js.masters.digite', { palavra: palavraExigida }) : '';
    }
    // A ação do painel não recarrega a página: destrava para a próxima confirmação. Com palavra, só destrava quando bater.
    ok.disabled = !!palavraExigida;
    conf.style.setProperty('--cor', op.cor);
    aoConfirmar = op.aoConfirmar;
    conf.hidden = false;
    (palavraExigida && entrada ? entrada : ok).focus();
  };

  if (conf) {
    conf.addEventListener('click', e => { if (e.target === conf) fecharConf(); });
    qs('[data-mp-conf-cancelar]', conf).addEventListener('click', fecharConf);
    // Um envio só: o botão trava no primeiro clique (o servidor recusaria o segundo, mas o flash confundiria).
    qs('[data-mp-conf-ok]', conf).addEventListener('click', e => {
      const acao = aoConfirmar; if (!acao) return;
      const digitado = entrada ? entrada.value.trim() : '';
      if (palavraExigida && digitado !== palavraExigida) return;
      aoConfirmar = null; e.currentTarget.disabled = true; acao(digitado);
    });
    if (entrada) entrada.addEventListener('input', () => { if (palavraExigida) qs('[data-mp-conf-ok]', conf).disabled = entrada.value.trim() !== palavraExigida; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !conf.hidden) fecharConf(); });
  }

  // Confirmação compartilhada com o painel.js (menu da conta: suspender, reativar e excluir com a palavra EXCLUIR).
  window.BJMasterConf = { abrir: abrirConf, fechar: fecharConf };

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

  /* ---------- remover Master secundário (só no HTML do Master primário) ---------- */
  raiz.querySelectorAll('[data-mp-remover-master]').forEach(btn => btn.addEventListener('click', () => {
    abrirConf({
      titulo: T('master.js.masters.remover'), icone: 'bi-person-dash', cor: COR_PERIGO, botao: T('master.js.masters.remover'),
      texto: T('master.js.masters.remover_texto', { nome: btn.getAttribute('data-nome') }),
      aoConfirmar: () => btn.closest('form').submit()
    });
  }));
})();
