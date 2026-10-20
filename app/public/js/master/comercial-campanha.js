/* BlueJus · Painel do Master, seção "Comercial e e-mails", bloco "Nova campanha de e-mail", fatia 2a
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, markup L.229-260 e lógica L.530-559;
   spec docs/specs/comercial-e-emails.md §8.2 a §8.4).
   - A grade de modelos vem do servidor (os fixos do dc e os próprios do Master, em comercial_modelo).
   - Editar um modelo (fixo ou próprio) vale para a prévia e o teste, sem guardar; trocar de modelo descarta a
     edição sem aviso (dc L.542, `cmEd: null`). "Salvar modelo" só no próprio.
   - A prévia é o HTML do servidor (o mesmo montador do envio) num iframe sandbox="" (srcdoc), pedida com 400 ms de
     espera entre teclas. "Baixar HTML" baixa esse HTML como email-CHAVE.html (dc L.558).
   - "Enviar teste" manda ao Master logado; as recusas (teto, descadastro) vêm do servidor.
   Nenhuma regra mora só aqui: o servidor escapa o texto, sanitiza o HTML e recusa o que não vale. */
(() => {
  'use strict';
  const raiz = document.querySelector('[data-mc-campanha]');
  const caixa = document.querySelector('[data-mc]');
  if (!raiz || !caixa) return;

  const TOKEN = caixa.dataset.token || '';
  const ROTAS = raiz.dataset;
  const el = seletor => raiz.querySelector(seletor);
  const elModelos = el('[data-mc-cp-modelos]');
  const elLista = el('[data-mc-cp-lista]');
  const elPrevia = el('[data-mc-cp-previa]');
  const elAviso = el('[data-mc-cp-aviso]');
  // Os 7 tipos de bloco do dc (L.533), na ordem dos botões "+ TIPO" (L.548).
  const TIPOS = {
    titulo: ['Título', 'bi-type-h2'], texto: ['Texto', 'bi-text-paragraph'], imagem: ['Imagem', 'bi-image'],
    botao: ['Botão', 'bi-hand-index'], noticias: ['Notícias', 'bi-list-ul'], colunas: ['2 colunas', 'bi-layout-split'],
    divisor: ['Divisor', 'bi-hr'],
  };
  // Os placeholders literais do dc (L.547).
  const PH_A = { noticias: 'Uma notícia por linha: Título|Resumo', imagem: 'Endereço da imagem (https://...)', botao: 'Texto do botão', colunas: 'Coluna da esquerda' };
  const PH_B = { botao: 'Link do botão', colunas: 'Coluna da direita' };

  let modelos = [];
  let mk = 'convite';
  let ed = null;
  let carregado = false;
  let espera = null;
  let pedidoDaPrevia = 0;
  let htmlDaPrevia = '';

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const base = () => modelos.find(m => m.chave === mk) || modelos[0] || null;
  // O modelo em edição: o escolhido com as edições por cima (dc L.530).
  const atual = () => Object.assign({}, base(), ed || {});
  const blocos = () => (atual().blocos || []).map(b => Object.assign({}, b));
  const icone = ic => (/^bi-[a-z0-9-]{1,40}$/.test(ic || '') ? ic : 'bi-file-earmark');

  function avisar(texto) {
    el('[data-mc-cp-msg]').textContent = texto;
    elAviso.hidden = !texto;
  }

  function editar(campo, valor) {
    ed = Object.assign({}, ed, { [campo]: valor });
    agendarPrevia();
  }

  function postar(url, corpo) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': TOKEN },
      body: JSON.stringify(corpo || {}),
    }).then(r => r.json().then(j => (r.ok ? j : Promise.reject(new Error(j.erro || 'Não foi possível concluir.')))));
  }

  // O que o servidor recebe: o modelo em edição, como o dc passa ao emailHTML (L.537).
  function pedido() {
    const m = atual();
    return {
      chave: m.chave, modo: m.modo, nome: m.nome, icone: m.icone, assunto: m.assunto || '', selo: m.selo || '', titulo: m.titulo || '',
      corpo: m.corpo || '', itens: m.itens || [], cta: m.cta || '', link: m.link || '', blocos: m.blocos || [], html: m.html || '',
    };
  }

  function desenharGrade() {
    elModelos.innerHTML = modelos.map(m => '<button type="button" class="mc-cp-modelo" data-mc-cp-modelo="' + esc(m.chave) + '" aria-pressed="' + (m.chave === mk) + '">'
      + '<i class="bi ' + esc(icone(m.icone)) + '" aria-hidden="true"></i>' + esc(m.nome) + '</button>').join('');
    elModelos.removeAttribute('aria-busy');
  }

  function desenharBlocos() {
    elLista.innerHTML = blocos().map((b, i) => {
      const [rot, ic] = TIPOS[b.t] || ['', 'bi-square'];
      const temA = b.t !== 'divisor';
      const temB = b.t === 'botao' || b.t === 'colunas' || b.t === 'imagem';
      return '<div class="mc-cp-bloco" data-mc-cp-bloco="' + i + '">'
        + '<div class="mc-cp-bloco-cabeca"><i class="bi ' + esc(ic) + '" aria-hidden="true"></i><span class="mc-cp-bloco-rot">' + esc(rot) + '</span>'
        + '<button type="button" class="mc-cp-bloco-btn" title="Subir" aria-label="Subir" data-mc-cp-mover="-1"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>'
        + '<button type="button" class="mc-cp-bloco-btn" title="Descer" aria-label="Descer" data-mc-cp-mover="1"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>'
        + '<button type="button" class="mc-cp-bloco-btn mc-cp-bloco-rm" title="Remover" aria-label="Remover" data-mc-cp-remover><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>'
        + (temA ? '<textarea class="mc-cp-bloco-a" rows="2" data-mc-cp-lado="a" placeholder="' + esc(PH_A[b.t] || '') + '">' + esc(b.a || '') + '</textarea>' : '')
        + (temB ? '<textarea class="mc-cp-bloco-b" rows="1" data-mc-cp-lado="b" placeholder="' + esc(PH_B[b.t] || 'Descrição da imagem') + '">' + esc(b.b || '') + '</textarea>' : '')
        + '</div>';
    }).join('');
  }

  function desenharNovos() {
    el('[data-mc-cp-novos]').innerHTML = Object.keys(TIPOS).map(k => '<button type="button" class="mc-cp-novo" data-mc-cp-novo-bloco="' + esc(k) + '">'
      + '<i class="bi bi-plus" aria-hidden="true"></i><i class="bi ' + esc(TIPOS[k][1]) + '" aria-hidden="true"></i>' + esc(TIPOS[k][0]) + '</button>').join('');
  }

  // Os campos do modelo em edição (dc L.546, L.555): simples, blocos ou HTML importado; o nome só no próprio.
  function desenharEditor() {
    const m = atual();
    raiz.querySelectorAll('[data-mc-cp-campo]').forEach(c => { c.value = m[c.dataset.mcCpCampo] || ''; });
    raiz.querySelectorAll('[data-mc-cp-so-proprio]').forEach(x => { x.hidden = !m.proprio; });
    el('[data-mc-cp-simples]').hidden = m.modo !== 'simples';
    el('[data-mc-cp-blocos]').hidden = m.modo !== 'blocos';
    el('[data-mc-cp-html]').hidden = m.modo !== 'html';
    desenharBlocos();
  }

  function desenhar() {
    desenharGrade();
    desenharEditor();
    atualizarPrevia();
  }

  function agendarPrevia() {
    clearTimeout(espera);
    espera = setTimeout(atualizarPrevia, 400);
  }

  function atualizarPrevia() {
    clearTimeout(espera);
    espera = null;
    if (!base()) return Promise.resolve('');
    const numero = ++pedidoDaPrevia;
    return postar(ROTAS.urlPrevia, pedido()).then(r => {
      if (numero === pedidoDaPrevia) {
        htmlDaPrevia = r.html;
        elPrevia.srcdoc = r.html;
      }
      return r.html;
    }).catch(e => { avisar(e.message); return ''; });
  }

  function trocarBlocos(lista) {
    ed = Object.assign({}, ed, { blocos: lista });
    desenharBlocos();
    agendarPrevia();
  }

  function carregar(escolher) {
    return fetch(ROTAS.urlModelos, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then(d => {
        carregado = true;
        modelos = d.modelos || [];
        if (escolher) mk = escolher;
        if (!modelos.some(m => m.chave === mk)) mk = 'convite';
        ed = null;
        desenhar();
      })
      .catch(() => avisar('Não foi possível carregar os modelos. Recarregue a página.'));
  }

  function aposSalvar(r) {
    return carregar(r.modelo.chave).then(() => avisar(r.mensagem));
  }

  function salvar() {
    const m = atual();
    if (!m.proprio) { avisar('Modelos padrão não são alterados. Use Duplicar para criar a sua versão.'); return; }
    postar(ROTAS.urlSalvar.replace('xidx', encodeURIComponent(m.id)), pedido()).then(aposSalvar).catch(e => avisar(e.message));
  }

  function excluir() {
    const m = atual();
    if (!m.proprio || !window.confirm('Excluir o modelo "' + m.nome + '"?')) return;
    postar(ROTAS.urlExcluir.replace('xidx', encodeURIComponent(m.id))).then(() => carregar('convite')).then(() => avisar('')).catch(e => avisar(e.message));
  }

  function importar(arquivo) {
    const leitor = new FileReader();
    leitor.onload = () => postar(ROTAS.urlCriar, { acao: 'importar', arquivo: arquivo.name, html: String(leitor.result) })
      .then(aposSalvar).catch(e => avisar(e.message));
    leitor.readAsText(arquivo);
  }

  // dc L.558: o HTML da prévia (atualizada antes, se havia edição esperando) num arquivo email-CHAVE.html.
  function baixar() {
    const pronto = espera !== null || !htmlDaPrevia ? atualizarPrevia() : Promise.resolve(htmlDaPrevia);
    pronto.then(html => {
      if (!html) return;
      const a = document.createElement('a');
      a.href = window.URL.createObjectURL(new Blob([html], { type: 'text/html' }));
      a.download = 'email-' + (atual().chave || 'campanha') + '.html';
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.setTimeout(() => window.URL.revokeObjectURL(a.href), 1000);
    });
  }

  raiz.addEventListener('click', e => {
    const modelo = e.target.closest('[data-mc-cp-modelo]');
    if (modelo) { mk = modelo.dataset.mcCpModelo; ed = null; avisar(''); desenhar(); return; }
    const bloco = e.target.closest('[data-mc-cp-bloco]');
    const i = bloco ? parseInt(bloco.dataset.mcCpBloco, 10) : -1;
    const mover = e.target.closest('[data-mc-cp-mover]');
    if (mover && i >= 0) {
      const lista = blocos();
      const j = i + parseInt(mover.dataset.mcCpMover, 10);
      if (j < 0 || j >= lista.length) return;
      [lista[i], lista[j]] = [lista[j], lista[i]];
      trocarBlocos(lista);
      return;
    }
    if (e.target.closest('[data-mc-cp-remover]') && i >= 0) { trocarBlocos(blocos().filter((_, j) => j !== i)); return; }
    const novo = e.target.closest('[data-mc-cp-novo-bloco]');
    if (novo) { trocarBlocos(blocos().concat({ t: novo.dataset.mcCpNovoBloco, a: '', b: '' })); return; }
    if (e.target.closest('[data-mc-cp-novo]')) { postar(ROTAS.urlCriar, { acao: 'novo' }).then(aposSalvar).catch(err => avisar(err.message)); return; }
    if (e.target.closest('[data-mc-cp-duplicar]')) { postar(ROTAS.urlCriar, { acao: 'duplicar', modelo: pedido() }).then(aposSalvar).catch(err => avisar(err.message)); return; }
    if (e.target.closest('[data-mc-cp-salvar]')) { salvar(); return; }
    if (e.target.closest('[data-mc-cp-excluir]')) { excluir(); return; }
    if (e.target.closest('[data-mc-cp-baixar]')) { baixar(); return; }
    if (e.target.closest('[data-mc-cp-teste]')) { postar(ROTAS.urlTeste, pedido()).then(r => avisar(r.mensagem)).catch(err => avisar(err.message)); }
  });

  raiz.addEventListener('input', e => {
    const campo = e.target.closest('[data-mc-cp-campo]');
    if (campo) { editar(campo.dataset.mcCpCampo, campo.value); return; }
    const lado = e.target.closest('[data-mc-cp-lado]');
    const bloco = e.target.closest('[data-mc-cp-bloco]');
    if (lado && bloco) {
      const lista = blocos();
      const i = parseInt(bloco.dataset.mcCpBloco, 10);
      if (!lista[i]) return;
      lista[i][lado.dataset.mcCpLado] = lado.value;
      ed = Object.assign({}, ed, { blocos: lista });
      agendarPrevia();
    }
  });

  raiz.addEventListener('change', e => {
    const entrada = e.target.closest('[data-mc-cp-importar]');
    if (!entrada || !entrada.files || !entrada.files[0]) return;
    importar(entrada.files[0]);
    entrada.value = '';
  });

  desenharNovos();
  const painel = document.getElementById('master-painel');
  if (painel) {
    painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === 'com' && !carregado) carregar(); });
  }
  if (/[#&]sec=com\b/.test(location.hash)) carregar();
})();
