/* BlueJus · Painel do Master, aba "Configurações da I.A.", bloco "Questões jurídicas" (master/_ia_juridico.html.twig).
   - O bloco vem desenhado do servidor; aqui só as ações, que redesenham o que mudou, só com textContent e createElement.
   - Status: grava ao mudar o select (POST JSON); a resposta traz o rótulo, o histórico e quantas seguem em aberto.
   - Versão ajustada: grava ao escolher o arquivo (POST multipart com "arquivo" e "questao"); a resposta traz as
     versões do documento, redesenhadas em todas as questões que pedem o mesmo documento.
   - Âncora #aba=ia&bloco=questoes-juridicas (o botão do aviso do topo): rola até o bloco depois que o painel.js
     abre a aba.
   - X-CSRF-Token em todo POST; recusa: a mensagem do servidor. Textos no catálogo (master.js.ia_juridico.*), lidos
     por BJ_T.t na hora de usar. O servidor confere tudo de novo (tipo pelo conteúdo, 20 MB, status da lista). */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mp-jur]');
  if (!raiz) return;

  const TOKEN = raiz.dataset.token || '';
  const elMsg = raiz.querySelector('[data-mp-jur-msg]');
  const elContagem = raiz.querySelector('[data-mp-jur-contagem]');
  // O catálogo e o BJ_T entram depois deste arquivo (fim da página): ler só na hora de usar.
  const T = (chave, params) => window.BJ_T.t(chave, params);
  let ocupado = false;

  function avisar(texto, erro) {
    elMsg.textContent = texto || '';
    elMsg.classList.toggle('is-erro', !!erro);
    elMsg.hidden = !texto;
  }

  function el(tag, classe, texto) {
    const e = document.createElement(tag);
    if (classe) e.className = classe;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  function enviar(url, opcoes) {
    const cabecalhos = Object.assign({ Accept: 'application/json', 'X-CSRF-Token': TOKEN }, opcoes.headers || {});
    return fetch(url, Object.assign({}, opcoes, { method: 'POST', credentials: 'same-origin', headers: cabecalhos }))
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error(j.erro || '')))));
  }

  function desenharHistorico(questao, historico) {
    const lista = questao.querySelector('[data-mp-jur-historico]');
    lista.textContent = '';
    if (!historico.length) lista.appendChild(el('li', 'mp-jur-vazio', T('master.js.ia_juridico.sem_historico')));
    historico.forEach(m => lista.appendChild(el('li', '', T('master.js.ia_juridico.historico_item', { de: m.de, para: m.para, por: m.por, quando: m.quando }))));
  }

  function desenharVersoes(documento, versoes) {
    raiz.querySelectorAll('[data-mp-jur-doc]').forEach(doc => {
      if (doc.dataset.mpJurDoc !== documento) return;
      const lista = doc.querySelector('[data-mp-jur-versoes]');
      lista.textContent = '';
      if (!versoes.length) lista.appendChild(el('li', 'mp-jur-vazio', T('master.js.ia_juridico.sem_versao')));
      versoes.forEach(v => {
        let texto = T('master.js.ia_juridico.versao_item', { nome: v.nome, por: v.por, quando: v.quando });
        if (v.questao) texto += ' ' + T('master.js.ia_juridico.versao_questao', { questao: v.questao });
        const li = el('li', '', texto + ' ');
        const a = el('a', '', T('master.js.ia_juridico.baixar'));
        a.href = v.url;
        li.appendChild(a);
        lista.appendChild(li);
      });
    });
  }

  function mudarStatus(questao, select) {
    if (ocupado) return;
    ocupado = true;
    select.disabled = true;
    avisar('');
    enviar(questao.dataset.urlStatus, { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: select.value }) })
      .then(d => {
        const selo = questao.querySelector('[data-mp-jur-selo]');
        selo.textContent = d.rotulo;
        selo.className = 'mp-jur-selo mp-jur-selo--' + d.status;
        select.value = d.status;
        select.dataset.anterior = d.status;
        desenharHistorico(questao, d.historico || []);
        elContagem.textContent = T('master.js.ia_juridico.em_aberto', { n: d.emAberto });
        avisar(T('master.js.ia_juridico.status_salvo', { titulo: questao.querySelector('[data-mp-jur-titulo]').textContent, status: d.rotulo }));
      })
      .catch(e => { select.value = select.dataset.anterior; avisar(e.message || T('master.js.ia_juridico.erro_salvar'), true); })
      .finally(() => { ocupado = false; select.disabled = false; });
  }

  function enviarArquivo(questao, doc, campo) {
    const arquivo = campo.files && campo.files[0];
    if (!arquivo || ocupado) return;
    ocupado = true;
    const rotulo = campo.closest('.mp-jur-enviar');
    rotulo.classList.add('is-ocupado');
    campo.disabled = true;
    avisar(T('master.js.ia_juridico.enviando'));
    const corpo = new FormData();
    corpo.append('arquivo', arquivo);
    corpo.append('questao', questao.dataset.mpJurQuestao);
    enviar(doc.dataset.urlEnviar, { body: corpo })
      .then(d => { desenharVersoes(d.documento, d.versoes || []); avisar(T('master.js.ia_juridico.enviado', { nome: arquivo.name })); })
      .catch(e => avisar(e.message || T('master.js.ia_juridico.erro_enviar'), true))
      .finally(() => { ocupado = false; campo.disabled = false; campo.value = ''; rotulo.classList.remove('is-ocupado'); });
  }

  raiz.querySelectorAll('[data-mp-jur-questao]').forEach(questao => {
    const select = questao.querySelector('[data-mp-jur-status]');
    select.dataset.anterior = select.value;
    select.addEventListener('change', () => mudarStatus(questao, select));
    questao.querySelectorAll('[data-mp-jur-doc]').forEach(doc => {
      const campo = doc.querySelector('[data-mp-jur-arquivo]');
      campo.addEventListener('change', () => enviarArquivo(questao, doc, campo));
    });
  });

  // O painel.js mostra a aba pelo hash no fim da página; a rolagem vem depois dele.
  function rolarSeAncora() {
    if (!/[#&]bloco=questoes-juridicas\b/.test(location.hash)) return;
    window.setTimeout(() => raiz.scrollIntoView({ block: 'start' }), 0);
  }
  if (document.readyState === 'complete') rolarSeAncora();
  else window.addEventListener('load', rolarSeAncora);
  window.addEventListener('hashchange', rolarSeAncora);
})();
