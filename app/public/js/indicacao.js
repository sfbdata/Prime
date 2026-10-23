/* BlueJus · Janela "Indicar o BlueJus" (spec indicar-bluejus.md; desenho bj-contas.js L98-133).
   Marcação em templates/indicacao/_janela.html.twig; o item do menu é [data-ind-abrir].
   O texto fixo vem do catálogo (BJ_T, publicas.js.indicacao, entregue pela _janela).
   Tudo o que vem do servidor entra por textContent/createElement (nunca innerHTML).
   WhatsApp e copiar agem no clique (o navegador só deixa abrir janela e escrever na área de transferência dentro do
   gesto) e registram em seguida; a trava conhecida já esconde os botões, como no desenho. */
(function () {
  'use strict';

  const raiz = () => document.getElementById('modalIndicacao');
  const $ = sel => raiz().querySelector(sel);
  let estado = null;

  function fecharMenu() {
    document.querySelectorAll('[data-mu-toggle]').forEach(t => { const d = window.bootstrap && bootstrap.Dropdown.getInstance(t); if (d) d.hide(); });
  }

  function pedir(url, opcoes) {
    const r = raiz();
    const cab = { Accept: 'application/json' };
    if (opcoes && opcoes.method === 'POST') { cab['Content-Type'] = 'application/json'; cab['X-CSRF-Token'] = r.dataset.token; }
    // Sessão expirada vira redirecionamento para o login: não pode cair dentro da janela.
    return fetch(url, Object.assign({ credentials: 'same-origin', redirect: 'manual', headers: cab }, opcoes || {}))
      .then(resp => {
        if (resp.type === 'opaqueredirect') return Promise.reject(new Error('sessao'));
        return resp.json().then(dados => ({ status: resp.status, dados }));
      });
  }

  function aviso(texto) {
    const el = $('[data-ind-aviso]');
    el.textContent = texto || '';
    el.hidden = !texto;
  }

  function item(i) {
    const linha = document.createElement('div'); linha.className = 'ind-item';
    const quem = document.createElement('span'); quem.className = 'ind-item-quem';
    quem.textContent = (i.nome ? i.nome + ' · ' : '') + (i.email || (i.canal === 'whatsapp' ? 'WhatsApp' : i.canal));
    const data = document.createElement('span'); data.className = 'ind-item-data'; data.textContent = i.em;
    const selo = document.createElement('span'); selo.className = 'ind-selo' + (i.contaCriada ? ' ind-selo-conta' : '');
    selo.textContent = i.contaCriada ? BJ_T.t('publicas.js.indicacao.conta_criada') : BJ_T.t('publicas.js.indicacao.enviada');
    linha.append(quem, data, selo);
    return linha;
  }

  function desenhar(d) {
    estado = d;
    const falta = d.pendencias.length > 0;
    $('[data-ind-pendencias]').hidden = !falta;
    $('[data-ind-pendencias-texto]').textContent = d.textoDasPendencias || '';
    $('[data-ind-trava]').hidden = falta || !d.trava;
    $('[data-ind-trava]').textContent = d.trava || '';
    $('[data-ind-envio]').hidden = falta || !!d.trava;
    $('[data-ind-nome]').textContent = d.nome;
    $('[data-ind-link]').textContent = d.link;
    $('#indNome').value = ''; $('#indEmail').value = '';
    $('#indMensagem').value = d.mensagem;
    $('[data-ind-total]').textContent = String(d.total);
    const lista = $('[data-ind-lista]');
    lista.replaceChildren();
    if (d.ultimas.length === 0) {
      const vazio = document.createElement('div'); vazio.className = 'ind-vazio'; vazio.textContent = BJ_T.t('publicas.js.indicacao.vazio');
      lista.append(vazio);
    } else {
      d.ultimas.forEach(i => lista.append(item(i)));
    }
  }

  function carregar(textoDoAviso) {
    return pedir(raiz().dataset.urlJanela).then(r => {
      if (r.status !== 200) throw new Error('janela');
      desenhar(r.dados);
      // Trava ou cadastro incompleto já aparecem na própria caixa: o aviso não repete.
      aviso(r.dados.pendencias.length || r.dados.trava ? '' : textoDoAviso);
    });
  }

  function falhou() { aviso(BJ_T.t('publicas.js.indicacao.falhou')); }

  function enviar(url, corpo) {
    raiz().querySelectorAll('.ind-acoes > button').forEach(b => { b.disabled = true; });
    return pedir(url, { method: 'POST', body: JSON.stringify(corpo || {}) })
      .then(r => {
        if (r.status === 200 || r.status === 422) return carregar(r.dados.mensagem);
        aviso(r.dados.mensagem || '');
      })
      .catch(falhou)
      .finally(() => raiz().querySelectorAll('.ind-acoes > button').forEach(b => { b.disabled = false; }));
  }

  function abrir() {
    if (!raiz()) return;
    fecharMenu();
    aviso('');
    carregar('').then(() => {
      const m = window.bootstrap && bootstrap.Modal.getOrCreateInstance(raiz());
      if (m) m.show();
    }).catch(() => { if (window.bjAviso) window.bjAviso(BJ_T.t('publicas.js.indicacao.abrir_falhou')); });
  }

  function podeEnviar() { return estado && estado.pendencias.length === 0 && !estado.trava; }

  document.addEventListener('click', e => {
    const t = e.target;
    if (t.closest('[data-ind-abrir]')) { e.preventDefault(); return abrir(); }
    if (!raiz() || !raiz().contains(t)) return;
    if (t.closest('[data-ind-email]')) {
      if (!podeEnviar()) return;
      return enviar(raiz().dataset.urlEmail, { email: $('#indEmail').value.trim(), nome: $('#indNome').value.trim() });
    }
    if (t.closest('[data-ind-whatsapp]')) {
      if (!podeEnviar()) return;
      window.open('https://wa.me/?text=' + encodeURIComponent($('#indMensagem').value), '_blank', 'noopener');
      return enviar(raiz().dataset.urlWhatsapp);
    }
    if (t.closest('[data-ind-copiar]')) {
      if (!podeEnviar()) return;
      if (navigator.clipboard) navigator.clipboard.writeText(estado.link).catch(() => {});
      return enviar(raiz().dataset.urlLink);
    }
  });
})();
