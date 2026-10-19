/* BlueJus · Painel do Master, aba Permissões: bloco "Masters do sistema"
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, linhas 317-336, 624 e 641-652).
   - select "Promover a Master": mostra "e-mail · OAB" do escolhido e a mensagem de erro sem escolha;
   - confirmação interna (promover e remover Master) no lugar do window.confirm. A mesma confirmação
     serve ao painel.js (suspender e reativar conta) por window.BJMasterConf.abrir(op).
   Nenhuma decisão mora só aqui: o servidor revalida tudo (CSRF, Master primário, regras da spec perfis-1.2-masters). */
(() => {
  'use strict';
  const raiz = document.getElementById('master-painel');
  if (!raiz) return;

  const COR_ROXO = '#7a3e8f';
  const COR_PERIGO = '#a3232b';
  const qs = (sel, el) => (el || raiz).querySelector(sel);

  /* ---------- confirmação ---------- */
  const conf = qs('[data-mp-conf]');
  let aoConfirmar = null;

  const fecharConf = () => { if (!conf) return; conf.hidden = true; aoConfirmar = null; };
  const abrirConf = op => {
    if (!conf) return;
    qs('[data-mp-conf-titulo]', conf).textContent = op.titulo;
    qs('[data-mp-conf-icone]', conf).className = 'bi ' + op.icone;
    qs('[data-mp-conf-texto]', conf).textContent = op.texto;
    const ok = qs('[data-mp-conf-ok]', conf);
    ok.textContent = op.botao;
    ok.disabled = false; // a ação do painel não recarrega a página: destrava para a próxima confirmação
    conf.style.setProperty('--cor', op.cor);
    aoConfirmar = op.aoConfirmar;
    conf.hidden = false;
    ok.focus();
  };

  if (conf) {
    conf.addEventListener('click', e => { if (e.target === conf) fecharConf(); });
    qs('[data-mp-conf-cancelar]', conf).addEventListener('click', fecharConf);
    // Um envio só: o botão trava no primeiro clique (o servidor recusaria o segundo, mas o flash confundiria).
    qs('[data-mp-conf-ok]', conf).addEventListener('click', e => { const acao = aoConfirmar; if (!acao) return; aoConfirmar = null; e.currentTarget.disabled = true; acao(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !conf.hidden) fecharConf(); });
  }

  // Confirmação compartilhada com o painel.js (menu da conta: suspender e reativar).
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
        msg.textContent = 'Escolha um usuário secundário cadastrado.';
        msg.classList.add('is-erro');
        return;
      }
      abrirConf({
        titulo: 'Promover a Master', icone: 'bi-stars', cor: COR_ROXO, botao: 'Confirmar promoção',
        texto: '"' + escolhido().getAttribute('data-nome') + '" passará a ter todos os poderes do sistema (contas, limites, planos, configurações e auditoria), como Master secundário. Ele não poderá excluir o Master primário.',
        aoConfirmar: () => form.submit()
      });
    });
  }

  /* ---------- remover Master secundário (só no HTML do Master primário) ---------- */
  raiz.querySelectorAll('[data-mp-remover-master]').forEach(btn => btn.addEventListener('click', () => {
    abrirConf({
      titulo: 'Remover Master', icone: 'bi-person-dash', cor: COR_PERIGO, botao: 'Remover Master',
      texto: '"' + btn.getAttribute('data-nome') + '" deixa de ser Master e perde os poderes do sistema. A remoção fica registrada na auditoria.',
      aoConfirmar: () => btn.closest('form').submit()
    });
  }));
})();
