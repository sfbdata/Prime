/* BlueJus · Painel do Master, seção "Comercial e e-mails", bloco "Nova campanha de e-mail", fatias 2a e 2b e o filtro da F3b
   (desenho docs/design/perfis-1.2/05 - Perfis 1.2 - VS 18.10.26.dc.html, markup L.229-260 e lógica L.530-560;
   spec docs/specs/comercial-e-emails.md §8.2 a §8.6).
   - A grade de modelos vem do servidor (os fixos do dc e os próprios do Master, em comercial_modelo).
   - Editar um modelo (fixo ou próprio) vale para a prévia e o teste, sem guardar; trocar de modelo descarta a
     edição sem aviso (dc L.542, `cmEd: null`). "Salvar modelo" só no próprio.
   - A prévia é o HTML do servidor (o mesmo montador do envio) num iframe sandbox="" (srcdoc), pedida com 400 ms de
     espera entre teclas. "Baixar HTML" baixa o mesmo e-mail como email-CHAVE.html (dc L.558), sempre com o exemplo fixo.
   - "Enviar teste" manda ao Master logado; as recusas (teto, descadastro) vêm do servidor.
   - 2b: o público (dc L.237-239; o CSV é lido no servidor, com a regra do dc L.545), a contagem e a linha de
     destinatários (L.252), a frequência (L.253), "Agendar" ou "Primeiro envio" (L.254, L.557) e "Enviar campanha"
     com o confirm literal do dc (L.560) e uma chave de idempotência por campanha (clique duplo não cria duas).
     A prévia na tela usa o primeiro destinatário do público (L.537), que vem da contagem; o arquivo baixado, não.
   Nenhuma regra mora só aqui: o servidor escapa o texto, sanitiza o HTML e recusa o que não vale.
   F3h (spec §10, "F3h: modo venda"; dc 05 VS 21.10.26 L627-645 e L846): não é mais um singleton. Cada raiz
   [data-mc-campanha] roda à parte, com o modo da caixa [data-modo] em volta (`com` na seção Comercial, `venda` na seção
   Indicações), o token e as URLs dela e o estado só dela. O script entra nos dois parciais; cada execução só inicia as
   raízes ainda não iniciadas. Os ganchos globais (window.McCampanha) são só da instância `com`. */
(() => {
  'use strict';
  /* A tabela única dos modos (dc L627-645): os modelos (null = todos; na venda os quatro do dc, mais os próprios), os
     públicos, as chaves do filtro que a tela manda, os números dos passos (`cmNPara`/`cmNMsg`), a seção do painel que
     carrega a instância e se ela responde pelos ganchos globais. */
  const MODOS = {
    com: {
      secao: 'com', modelos: null, publicos: ['leads', 'prim', 'sec', 'todos', 'csv', 'indiv'],
      filtros: ['q', 'gen', 'id', 'uf', 'papel', 'cargo', 'oab', 'plano', 'aniv'], passos: { para: 2, msg: 3 }, ganchos: true,
    },
    venda: {
      secao: 'ind', modelos: ['convite', 'vagas', 'news', 'novidades'], publicos: ['leads', 'csv', 'indiv'],
      filtros: ['q', 'uf'], passos: { para: 1, msg: 2 }, ganchos: false,
    },
  };

  function iniciar(raiz) {
    const caixa = raiz.closest('[data-modo]');
    if (!caixa || raiz.dataset.mcIniciada) return;
    raiz.dataset.mcIniciada = '1';
    const MODO = Object.prototype.hasOwnProperty.call(MODOS, caixa.dataset.modo) ? caixa.dataset.modo : 'com';
    const M = MODOS[MODO];

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
    // 2b: o público escolhido (dc L.530, `pub = s.cmP || 'leads'`), as listas informadas, a frequência e a contagem.
    let publico = 'leads';
    let csvTexto = '';
    let csvNome = '';
    let individual = '';
    let frequencia = 'unica';
    let contagem = null;
    // F3b: o "Filtrar destinatários" (dc 05 VS 21.10.26, L260-264 e L662-664), nas chaves do dc; vazio = todos.
    let filtro = {};
    // F3a: o nome só da prévia (dc 21.10 L270) e o aparelho do "Ver como vai chegar" (L670).
    let nomeDaPrevia = '';
    let ultimaPrevia = '';
    let pedidoDaContagem = 0;
    let esperaDaContagem = null;
    let chave = '';

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

    /* dc 21.10 L659: o emoji entra no fim do texto, com um espaço antes se faltar. */
    function inserirEmoji(emoji) {
      const corpo = String(atual().corpo || '');
      const novo = corpo + (corpo === '' || corpo.endsWith(' ') ? '' : ' ') + emoji;
      editar('corpo', novo);
      const caixaDeTexto = el('[data-mc-cp-campo="corpo"]');
      if (caixaDeTexto) { caixaDeTexto.value = novo; caixaDeTexto.focus(); }
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

    // A chave de idempotência da campanha: nova depois de cada envio aceito; a mesma se o pedido se repetir.
    function novaChave() {
      if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
      const bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }

    /* dc 21.10 L665: "N destinatário(s) · X descadastrado(s) retirado(s) · Y repetido(s) removido(s) · Z inválido(s)
       ignorado(s)", ou " · lista limpa". O B1 (sem autorização) é regra do sistema que o dc não tem: entra no mesmo tom. */
    function linhaDeDestinatarios(c) {
      const partes = [
        c.descadastrados ? c.descadastrados + ' descadastrado(s) retirado(s)' : '',
        c.repetidos ? c.repetidos + ' repetido(s) removido(s)' : '',
        c.invalidos ? c.invalidos + ' inválido(s) ignorado(s)' : '',
        c.semAutorizacao ? c.semAutorizacao + ' sem autorização retirado(s)' : '',
      ].filter(Boolean);

      return c.destinatarios + ' destinatário(s)' + partes.map(x => ' · ' + x).join('') + (partes.length ? '' : ' · lista limpa');
    }

    function desenharPublicos() {
      if (!M.publicos.includes(publico)) publico = 'leads';
      raiz.querySelectorAll('[data-mc-cp-publico]').forEach(b => {
        b.setAttribute('aria-pressed', String(b.dataset.mcCpPublico === publico));
        const n = b.querySelector('[data-mc-cp-n]');
        if (contagem && n) n.textContent = String(contagem.publicos[b.dataset.mcCpPublico] || 0);
      });
      el('[data-mc-cp-csv]').hidden = publico !== 'csv';
      el('[data-mc-cp-individual]').hidden = publico !== 'indiv';
      // dc L.545: "NOME · N e-mail(s)" depois de ler o arquivo.
      el('[data-mc-cp-csv-txt]').textContent = csvNome ? csvNome + ' · ' + (contagem ? contagem.publicos.csv : 0) + ' e-mail(s)' : 'Selecionar arquivo .csv';
      el('[data-mc-cp-dest]').textContent = contagem ? linhaDeDestinatarios(contagem) : '';
      desenharFiltro();
    }

    const filtroLimpo = () => Object.fromEntries(Object.entries(filtro).filter(([k, v]) => v && M.filtros.includes(k)));
    const mesmoFiltro = (a, b) => { const ka = Object.keys(a).sort(); return ka.length === Object.keys(b).length && ka.every(k => a[k] === b[k]); };

    /* dc L662-663: fechado, o selo com quantos campos estão preenchidos (a busca conta) e "N de M com filtro" ou "Todos
       (M)"; aberto, "N de M atendem ao filtro · K sem essa informação no cadastro (ficam de fora)" ou "Enviando para
       todos da lista (M)". Os números vêm do servidor (F2). */
    function desenharFiltro() {
      const caixa = el('[data-mc-cf]');
      if (!caixa) return;
      const ativo = filtroLimpo();
      const quantos = Object.keys(ativo).length;
      const f = contagem && contagem.filtro ? contagem.filtro : null;
      const selo = el('[data-mc-cf-selo]');
      selo.hidden = quantos === 0;
      selo.textContent = String(quantos);
      const m = f ? f.base : 0;
      el('[data-mc-cf-curto]').textContent = !f ? '' : quantos ? f.atendem + ' de ' + m + ' com filtro' : 'Todos (' + m + ')';
      el('[data-mc-cf-resultado]').textContent = !f ? '' : quantos
        ? f.atendem + ' de ' + m + ' atendem ao filtro' + (f.semInformacao ? ' · ' + f.semInformacao + ' sem essa informação no cadastro (ficam de fora)' : '')
        : 'Enviando para todos da lista (' + m + ')';
      el('[data-mc-cf-limpar]').hidden = quantos === 0;
      caixa.querySelectorAll('[data-mc-cf-campo]').forEach(c => { if (c.value !== (filtro[c.dataset.mcCfCampo] || '')) c.value = filtro[c.dataset.mcCfCampo] || ''; });
      caixa.querySelectorAll('[data-mc-cf-atalho]').forEach(b => {
        let o = {};
        try { o = JSON.parse(b.dataset.mcCfAtalho) || {}; } catch (x) { o = {}; }
        if (Array.isArray(o)) o = {};
        b.setAttribute('aria-pressed', String(mesmoFiltro(o, ativo)));
      });
    }

    function abrirFiltro() {
      const painelDoFiltro = el('[data-mc-cf-painel]');
      const aberto = painelDoFiltro.hidden;
      painelDoFiltro.hidden = !aberto;
      const botao = el('[data-mc-cf-abrir]');
      botao.setAttribute('aria-expanded', String(aberto));
      el('[data-mc-cf-seta]').className = 'bi ' + (aberto ? 'bi-chevron-up' : 'bi-chevron-down') + ' mc-cf-seta';
    }

    /* dc L664: o atalho troca o filtro inteiro (a busca também sai). */
    function usarAtalho(botao) {
      let o = {};
      try { o = JSON.parse(botao.dataset.mcCfAtalho) || {}; } catch (x) { o = {}; }
      filtro = Array.isArray(o) ? {} : Object.assign({}, o);
      desenharFiltro();
      contar();
    }

    /* dc 21.10 L656: "Programar envio" no botão quando há data; o relógio da linha de destinatários com a data e a
       repetição. */
    function desenharFrequencia() {
      raiz.querySelectorAll('[data-mc-cp-freq]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.mcCpFreq === frequencia)));
      const agenda = el('[data-mc-cp-agenda]').value;
      el('[data-mc-cp-enviar-txt]').textContent = agenda ? 'Programar envio' : 'Enviar';
      const quando = agenda ? new Date(agenda).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '';
      const repete = { semanal: 'semanal', quinzenal: 'quinzenal', mensal: 'mensal' }[frequencia] || '';
      const texto = [quando, repete].filter(Boolean).join(' · ');
      el('[data-mc-cp-ag]').hidden = texto === '';
      el('[data-mc-cp-ag-txt]').textContent = texto;
    }

    function programar(abrir) {
      const janela = el('[data-mc-cp-programar]');
      janela.hidden = !abrir;
      el('[data-mc-cp-mais]').setAttribute('aria-expanded', String(abrir));
    }

    /* dc 21.10 L499-501, L670: o e-mail como chega, no computador (100%) ou no celular (375 px), com o assunto, o
       remetente real, o primeiro destinatário e o resumo de 110 caracteres. */
    function verComoChega(aparelho) {
      const janela = el('[data-mc-cp-ver-janela]');
      if (!aparelho) { janela.hidden = true; return; }
      const m = atual();
      const nome = nomeDaPrevia || (contagem && contagem.exemplo && contagem.exemplo.nome ? String(contagem.exemplo.nome).split(' ')[0] : 'Dra. Ana');
      const texto = String(m.corpo || ((m.blocos || []).find(b => b.t === 'texto') || {}).a || '');
      el('[data-mc-cp-ver-assunto]').textContent = m.assunto || '';
      el('[data-mc-cp-ver-para]').textContent = contagem && contagem.exemplo ? contagem.exemplo.email : 'destinatario@exemplo.com.br';
      el('[data-mc-cp-ver-resumo]').textContent = texto.replace(/\{nome\}/g, nome).slice(0, 110);
      el('[data-mc-cp-ver-quadro]').dataset.dev = aparelho;
      raiz.querySelectorAll('[data-mc-cp-ver-dev]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.mcCpVerDev === aparelho)));
      const quadro = el('[data-mc-cp-ver-iframe]');
      if (quadro.srcdoc !== ultimaPrevia) quadro.srcdoc = ultimaPrevia;
      janela.hidden = false;
    }

    /* dc 21.10 L653: no modelo fixo, pede o nome e guarda a cópia em "Meus modelos" no mesmo modo. */
    function salvarComoMeu() {
      const m = atual();
      const nome = window.prompt('Nome do seu modelo (o modelo padrão continua guardado):', (m.nome || 'Modelo') + ' (meu)');
      if (!nome || !nome.trim()) return;
      postar(ROTAS.urlCriar, { acao: 'salvar_como', modelo: pedido(), nome: nome.trim() }).then(aposSalvar).catch(e => avisar(e.message));
    }

    function pedidoDoPublico() {
      const corpo = { publico, csv: publico === 'csv' ? csvTexto : '', individual: publico === 'indiv' ? individual : '', filtro: filtroLimpo() };
      // F3h: o servidor confere o modo venda (público, modelo e filtro) na contagem e no envio.
      return MODO === 'venda' ? Object.assign(corpo, { modo: 'venda' }) : corpo;
    }

    /* F3h, dc L644 (`cmLeadAdd`): pede os e-mails com o texto do dc; cada um recebe a confirmação dupla da Base de
       contatos, e o servidor responde quantos pedidos saíram, quantos já existiam e quantos foram barrados. */
    function cadastrarContatos() {
      const texto = window.prompt('E-mail(s) de novos contatos (separe por vírgula). Só cadastre quem autorizou receber (LGPD):', '');
      if (!texto || !texto.trim()) return;
      postar(ROTAS.urlContatos, { emails: texto })
        .then(r => { publico = 'leads'; avisar(r.mensagem); desenharPublicos(); contar(); })
        .catch(e => avisar(e.message));
    }

    function contar() {
      clearTimeout(esperaDaContagem);
      esperaDaContagem = null;
      const numero = ++pedidoDaContagem;
      // Devolve se a contagem vale (a recusa do servidor, como o CSV grande demais, vai à mensagem).
      return postar(ROTAS.urlContagem, pedidoDoPublico()).then(c => {
        if (numero !== pedidoDaContagem) return false;
        contagem = c;
        desenharPublicos();
        atualizarPrevia();
        return true;
      }).catch(e => {
        if (publico === 'csv') { csvTexto = ''; csvNome = ''; }
        contagem = null;
        desenharPublicos();
        avisar(e.message);
        return false;
      });
    }

    function agendarContagem() {
      clearTimeout(esperaDaContagem);
      esperaDaContagem = setTimeout(contar, 400);
    }

    // dc L.560: sem destinatário, a mensagem; senão o confirm literal e o pedido ao servidor.
    function enviarCampanha() {
      const pronto = esperaDaContagem !== null || !contagem ? contar() : Promise.resolve(true);
      pronto.then(valeu => {
        if (!valeu) return;
        if (!contagem || !contagem.destinatarios) { avisar('Nenhum destinatário nesta lista.'); return; }
        const agenda = el('[data-mc-cp-agenda]').value;
        const m = atual();
        if (!window.confirm('Enviar "' + (m.assunto || '') + '" para ' + contagem.destinatarios + ' destinatário(s)' + (agenda ? ' em ' + agenda.replace('T', ' ') : ' agora') + '?')) return;
        if (!chave) chave = novaChave();
        const botao = el('[data-mc-cp-enviar]');
        botao.disabled = true;
        // O que outras partes da Central acrescentam ao envio (ex.: a confirmação de leitura da F3g, do 0d): os ganchos
        // globais, só na instância `com` (F3h), e os desta instância, pendurados na raiz (`raiz.mcExtrasDoEnvio`).
        const globais = (M.ganchos && window.McCampanha && window.McCampanha.extrasDoEnvio) || [];
        const extras = [...globais, ...(raiz.mcExtrasDoEnvio || [])].map(f => { try { return f() || {}; } catch (x) { return {}; } });
        postar(ROTAS.urlCampanha, Object.assign(pedidoDoPublico(), ...extras, { chave, modelo: pedido(), frequencia, agendar: agenda }))
          .then(r => {
            chave = '';
            el('[data-mc-cp-agenda]').value = '';
            desenharFrequencia();
            avisar(r.mensagem);
            document.dispatchEvent(new CustomEvent('mc:campanha-confirmada'));
          })
          .catch(e => avisar(e.message))
          .finally(() => { botao.disabled = false; });
      });
    }

    function lerCsv(arquivo) {
      const leitor = new FileReader();
      leitor.onload = () => { csvTexto = String(leitor.result); csvNome = arquivo.name; contar(); };
      leitor.readAsText(arquivo);
    }

    function desenharGrade() {
      if (!elModelos) return; // modo venda: sem o seletor de modelo (dc `cmMostraModelo`)
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
      raiz.querySelectorAll('[data-mc-cp-so-fixo]').forEach(x => { x.hidden = !!m.proprio; });
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
      const exemplo = contagem && contagem.exemplo ? { exemplo: contagem.exemplo } : {};
      return postar(ROTAS.urlPrevia, Object.assign(pedido(), exemplo, { nomeDaPrevia })).then(r => {
        if (numero === pedidoDaPrevia) { elPrevia.srcdoc = r.html; ultimaPrevia = r.html; }
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
          modelos = (d.modelos || []).filter(m => !M.modelos || m.proprio || M.modelos.includes(m.chave));
          if (escolher) mk = escolher;
          if (!modelos.some(m => m.chave === mk)) mk = 'convite';
          ed = null;
          desenhar();
          if (!contagem) contar();
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
      postar(ROTAS.urlExcluir.replace('xidx', encodeURIComponent(m.id))).then(() => carregar('convite')).catch(e => avisar(e.message));
    }

    function importar(arquivo) {
      const leitor = new FileReader();
      leitor.onload = () => postar(ROTAS.urlCriar, { acao: 'importar', arquivo: arquivo.name, html: String(leitor.result) })
        .then(aposSalvar).catch(e => avisar(e.message));
      leitor.readAsText(arquivo);
    }

    // dc L.558: o HTML do e-mail num arquivo email-CHAVE.html, SEMPRE com o exemplo fixo ("Dra. Ana",
    // teste@bluejus.com.br): o arquivo baixado não leva o endereço nem o link de descadastro de uma pessoa real.
    function baixar() {
      postar(ROTAS.urlPrevia, pedido()).then(r => r.html).catch(e => { avisar(e.message); return ''; }).then(html => {
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
      // dc L.542: trocar de modelo não limpa a mensagem.
      if (modelo) { mk = modelo.dataset.mcCpModelo; ed = null; desenhar(); return; }
      const botaoDoPublico = e.target.closest('[data-mc-cp-publico]');
      if (botaoDoPublico) { publico = botaoDoPublico.dataset.mcCpPublico; desenharPublicos(); contar(); return; }
      if (e.target.closest('[data-mc-cp-lead-add]')) { cadastrarContatos(); return; }
      const botaoDaFrequencia = e.target.closest('[data-mc-cp-freq]');
      if (botaoDaFrequencia) { frequencia = botaoDaFrequencia.dataset.mcCpFreq; desenharFrequencia(); return; }
      if (e.target.closest('[data-mc-cp-enviar]')) { enviarCampanha(); return; }
      if (e.target.closest('[data-mc-cf-abrir]')) { abrirFiltro(); return; }
      if (e.target.closest('[data-mc-cp-descad]')) { document.dispatchEvent(new CustomEvent('mc:descadastrados-abrir')); return; }
      const emoji = e.target.closest('[data-mc-cp-emoji]');
      if (emoji) { inserirEmoji(emoji.dataset.mcCpEmoji); return; }
      if (e.target.closest('[data-mc-cp-mais]')) { programar(el('[data-mc-cp-programar]').hidden); return; }
      if (e.target.closest('[data-mc-cp-concluir]')) { programar(false); return; }
      if (e.target.closest('[data-mc-cp-ver]')) { verComoChega('pc'); return; }
      const aparelho = e.target.closest('[data-mc-cp-ver-dev]');
      if (aparelho) { verComoChega(aparelho.dataset.mcCpVerDev); return; }
      if (e.target.closest('[data-mc-cp-ver-fechar]')) { verComoChega(null); return; }
      if (e.target.closest('[data-mc-cp-salvar-como]')) { salvarComoMeu(); return; }
      const atalho = e.target.closest('[data-mc-cf-atalho]');
      if (atalho) { usarAtalho(atalho); return; }
      if (e.target.closest('[data-mc-cf-limpar]')) { filtro = {}; desenharFiltro(); contar(); return; }
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
      if (campo && campo.dataset.mcCpCampo === 'selo') {
        // dc 21.10 L660: o selo em maiúsculas ao digitar.
        const posicao = campo.selectionStart;
        campo.value = campo.value.toUpperCase();
        if (posicao !== null) campo.setSelectionRange(posicao, posicao);
      }
      if (campo) { editar(campo.dataset.mcCpCampo, campo.value); return; }
      if (e.target.closest('[data-mc-cp-nome-previa]')) { nomeDaPrevia = e.target.value.trim(); agendarPrevia(); return; }
      if (e.target.closest('[data-mc-cp-individual]')) { individual = e.target.value; agendarContagem(); return; }
      const campoDoFiltro = e.target.closest('input[data-mc-cf-campo]');
      if (campoDoFiltro) { filtro[campoDoFiltro.dataset.mcCfCampo] = campoDoFiltro.value; agendarContagem(); return; }
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
      if (e.target.closest('[data-mc-cp-agenda]')) { desenharFrequencia(); return; }
      const selectDoFiltro = e.target.closest('select[data-mc-cf-campo]');
      if (selectDoFiltro) { filtro[selectDoFiltro.dataset.mcCfCampo] = selectDoFiltro.value; desenharFiltro(); contar(); return; }
      const csv = e.target.closest('[data-mc-cp-csv-arquivo]');
      if (csv) {
        if (csv.files && csv.files[0]) lerCsv(csv.files[0]);
        csv.value = '';
        return;
      }
      const entrada = e.target.closest('[data-mc-cp-importar]');
      if (!entrada || !entrada.files || !entrada.files[0]) return;
      importar(entrada.files[0]);
      entrada.value = '';
    });

    /* O N do botão "Descadastrados (N)" vem do resumo da F3c (0d). */
    document.addEventListener('mc:resumo', e => {
      const n = el('[data-mc-cp-descad-n]');
      if (n && e.detail && Number.isFinite(e.detail.descadastrados)) n.textContent = String(e.detail.descadastrados);
    });
    /* dc 21.10 L293: um fundo de tela inteira fecha o PROGRAMAR ENVIO; aqui, o clique fora dele e do botão da seta. */
    document.addEventListener('click', e => {
      const janela = el('[data-mc-cp-programar]');
      if (janela.hidden || janela.contains(e.target) || e.target.closest('[data-mc-cp-mais]')) return;
      programar(false);
    });
    document.addEventListener('keydown', e => {
      if (e.key !== 'Escape') return;
      if (!el('[data-mc-cp-ver-janela]').hidden) verComoChega(null);
      if (!el('[data-mc-cp-programar]').hidden) programar(false);
    });
    /* Para as outras partes da Central: o modal de teste da F3f (7d) lê o modelo que o editor mostra, no formato que o POST
       de teste recebe; a F3g (0d) registra o que vai junto no envio. Cada raiz também expõe o próprio modelo
       (`raiz.mcModeloAtual`), para quem atende um clique ler o da instância de onde ele veio, não o da seção Comercial. */
    raiz.mcModeloAtual = () => pedido();
    if (M.ganchos) {
      window.McCampanha = Object.assign(window.McCampanha || {}, {
        modeloAtual: () => pedido(),
        // F3g (0d): funções que devolvem um objeto para mesclar no corpo do "Enviar campanha".
        extrasDoEnvio: (window.McCampanha && window.McCampanha.extrasDoEnvio) || [],
      });
    }

    // dc L258/L268: os números dos passos do modo (o Twig já escreve os mesmos; aqui a tabela manda).
    raiz.querySelectorAll('[data-mc-cp-passo-n]').forEach(n => { n.textContent = String(M.passos[n.dataset.mcCpPassoN] || n.textContent); });
    desenharNovos();
    desenharFrequencia();
    const painel = document.getElementById('master-painel');
    if (painel) {
      painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === M.secao && !carregado) carregar(); });
    }
    if (new RegExp('[#&]sec=' + M.secao + '\\b').test(location.hash)) carregar();
  }

  document.querySelectorAll('[data-mc-campanha]').forEach(iniciar);
})();
