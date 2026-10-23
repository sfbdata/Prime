/* BlueJus · Painel do Master, seção "Comercial e e-mails", "Relatório como caixa de entrada" da Central de envios (F3d)
   (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html, marcação L300-305 e L502-514,
   lógica L698-717; spec docs/specs/comercial-relatorio.md).
   - A lista vem do servidor (GET data-url-lista?aba=&q=&p=): a contagem das 3 abas, a faixa "1 a 50 de N" e as
     linhas com o selo do estado, o público e o total, o nome e o subtítulo, a leitura (✓ cinza enviado, ✓ verde
     recebido, ✓✓ verde com o %: aberto e lido; o tooltip com os números) e a data ("14:30" hoje, "21 out" outro dia).
   - A busca espera 300 ms entre teclas e volta à página 1. A seleção vale para a página na tela.
   - Clicar na linha abre o visualizador (GET data-url-campanha): Voltar, Duplicar, Cancelar envio (só quando o
     servidor diz `cancelavel`), os 4 KPIs, o HTML num iframe sandbox="" (srcdoc) e os destinatários com o estado.
   - "Duplicar" é só no cliente. Contrato com o editor da campanha: dispara na raiz do relatório o evento cancelável
       `mc:duplicar-campanha` (bubbles: true), detail { assunto, titulo, mensagem }.
     O editor que tratar o evento chama `preventDefault()` e preenche a si mesmo. Se ninguém tratar, o relatório
     preenche os campos [data-mc-cp-campo="assunto"] e [data-mc-cp-campo="titulo"] da mesma seção (a raiz
     `.mc[data-modo]` que o contém) com um evento `input` (o editor escuta `input` neles) e rola até o editor.
   - "Cancelar envio" pede confirmação e manda POST com o token no header X-CSRF-Token; a recusa vem em {erro}.
   - Recarrega quando a seção abre e depois de cada "Enviar campanha" aceito (evento `mc:campanha-confirmada`).
   F3e (spec docs/specs/comercial-marcas.md):
   - Marcas de cada Master, no servidor (POST data-url-marcas {ids, acao}, token no header): a estrela da linha
     (alterna sem abrir), os botões da seleção (Arquivar ou Desarquivar, Estrela, a lixeira "Remover do relatório") e
     o menu do botão direito. Depois da ação da seleção, a seleção limpa e a lista recarrega.
   - Abas Com estrela e Arquivadas (a contagem vem do servidor, como as outras).
   - Menu do botão direito (dc L504 e L704): fecha no clique fora, no botão direito fora e no Esc. "Duplicar como nova"
     busca a campanha (o mesmo GET do visualizador) e dispara o mesmo `mc:duplicar-campanha`. "Encaminhar para um
     e-mail" fica desabilitado no parcial até o dono decidir.
   - "Reenviar a quem não leu" é só aviso (o reenvio depende do rastreio, F3g).
   - "Exportar destinatários": o CSV vem do servidor (GET data-url-csv?formato=completo do visualizador, emails do
     menu) e é baixado como destinatarios-{id}.csv.
   Duas instâncias (spec docs/specs/comercial-relatorio-venda.md): o mesmo relatório na seção Comercial
   (data-mc-relatorio="com") e na seção Indicações (data-mc-relatorio="ind"). Um init por raiz, idempotente; estado,
   timers e seleção de cada uma. O arquivo vem uma vez só (com a instância da seção Comercial, que vem antes no HTML) e
   inicia as raízes quando o documento termina de carregar.
   Textos no catálogo (`comercial_relatorio.js`, spec idioma-do-sistema §8), lidos por BJ_T.t; o que vem do servidor
   (rótulos de estado, KPIs, dica e leitura, recusas) fica como está. Os ouvintes do document
   agem na instância envolvida (Esc: a que tem o menu aberto); `mc:campanha-confirmada` e `mc:relatorio-recarregar`
   recarregam todas. Marcar ou cancelar numa recarrega a outra (as duas mostram a mesma lista). */
(() => {
  'use strict';
  const MARCADOR = '999999999';
  const instancias = [];

  const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const url = (base, id) => base.replace(MARCADOR, String(id));

  // As outras instâncias mostram a mesma lista: recarregam depois de uma marca ou de um cancelamento.
  function recarregarOutras(atual) {
    instancias.forEach(i => { if (i !== atual) i.carregar(); });
  }

  function iniciar(raiz) {
    if (raiz.dataset.mcRlIniciado) return;
    raiz.dataset.mcRlIniciado = '1';
    const secao = raiz.dataset.mcRelatorio || 'com';
    const el = s => raiz.querySelector(s);
    const lista = el('[data-mc-rl-lista]');
    const ver = el('[data-mc-rl-ver]');
    const busca = el('[data-mc-rl-busca]');
    const menu = el('[data-mc-rl-ctx]');
    const estado = { aba: 'env', q: '', p: 1, paginas: 1, ids: [], sel: {}, aberta: null, linhas: {}, ctx: null };
    let carregado = false;
    let espera = 0;
    const instancia = { carregar: () => carregar() };

    const POST = () => ({ method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': raiz.dataset.token || '' } });

    function pedir(endereco, opcoes) {
      return fetch(endereco, Object.assign({ headers: { Accept: 'application/json' }, credentials: 'same-origin' }, opcoes || {}))
        .then(r => r.json().catch(() => ({})).then(d => (r.ok ? d : Promise.reject(new Error(d.erro || BJ_T.t('comercial_relatorio.js.erro_concluir'))))));
    }

    function avisar(texto) {
      const caixa = el('[data-mc-rl-msg]');
      el('[data-mc-rl-msg-texto]').textContent = texto || '';
      caixa.hidden = !texto;
    }

    // dc L702: "14:30" se for hoje; senão "21 out". A hora vem do BJFormato (idioma da página); o "21 out" (dia e mês
    // curto sem ponto) ele ainda não cobre e fica no formato fixo.
    function quando(iso) {
      const d = new Date(iso);
      if (isNaN(d.getTime())) return '';
      if (d.toDateString() === new Date().toDateString()) return window.BJFormato ? window.BJFormato.hora(d) : d.toLocaleTimeString('pt-BR').slice(0, 5);
      return d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' }).replace('.', '');
    }

    function linha(c) {
      const m = c.metricas || {};
      const le = m.leitura === 'lido' ? 'mc-rl-le-lido' : m.leitura === 'entregue' ? 'mc-rl-le-entregue' : 'mc-rl-le-enviado';
      const icone = m.leitura === 'lido' ? 'bi-check2-all' : 'bi-check2';
      const marcada = !!estado.sel[c.id];
      return '<div class="mc-rl-linha' + (marcada ? ' mc-rl-marcada' : '') + '" data-mc-rl-id="' + esc(c.id) + '" title="' + esc(BJ_T.t('comercial_relatorio.js.linha_dica')) + '">'
        + '<button type="button" class="mc-rl-sel" data-mc-rl-sel aria-label="' + esc(BJ_T.t('comercial_relatorio.js.selecionar')) + '" aria-pressed="' + marcada + '"><i class="bi ' + (marcada ? 'bi-check-square-fill' : 'bi-square') + '" aria-hidden="true"></i></button>'
        + '<button type="button" class="mc-rl-estrela' + (c.estrela ? ' mc-rl-com-estrela' : '') + '" data-mc-rl-estrela aria-label="' + esc(c.estrela ? BJ_T.t('comercial_relatorio.js.estrela_remover') : BJ_T.t('comercial_relatorio.js.estrela_marcar')) + '" aria-pressed="' + !!c.estrela + '"><i class="bi ' + (c.estrela ? 'bi-star-fill' : 'bi-star') + '" aria-hidden="true"></i></button>'
        + '<span class="mc-rl-publico">' + esc(c.publico) + ' <span class="mc-rl-total">' + esc(c.total) + '</span></span>'
        + '<span class="mc-rl-meio"><span class="mc-rl-st mc-rl-st-' + esc(c.estado) + '">' + esc(c.rotulo) + '</span>'
        + '<span class="mc-rl-nome"><b>' + esc(c.nome) + '</b><span class="mc-rl-sub"> · ' + esc(c.sub) + '</span></span></span>'
        + '<span class="mc-rl-le ' + le + '" title="' + esc(m.dica) + '"><i class="bi ' + icone + '" aria-hidden="true"></i>' + esc(m.percentual) + '</span>'
        + '<span class="mc-rl-data">' + esc(quando(c.data)) + '</span>'
        + '</div>';
    }

    function desenharSelecao() {
      const n = estado.ids.filter(i => estado.sel[i]).length;
      const ic = el('[data-mc-rl-sel-ic]');
      ic.className = 'bi ' + (n && n === estado.ids.length ? 'bi-check-square-fill' : n ? 'bi-dash-square' : 'bi-square');
      const rotulo = el('[data-mc-rl-sel-n]');
      rotulo.textContent = BJ_T.t('comercial_relatorio.js.selecionadas', { n: n });
      rotulo.hidden = n === 0;
      const arquivar = el('[data-mc-rl-marcar="arquivar"]');
      const arqTxt = estado.aba === 'arq' ? BJ_T.t('comercial_relatorio.js.desarquivar') : BJ_T.t('comercial_relatorio.js.arquivar');
      arquivar.title = arqTxt;
      el('[data-mc-rl-arq-txt]').textContent = arqTxt;
      raiz.querySelectorAll('[data-mc-rl-marcar]').forEach(b => { b.hidden = n === 0; });
      lista.querySelectorAll('[data-mc-rl-id]').forEach(l => {
        const marcada = !!estado.sel[l.dataset.mcRlId];
        l.classList.toggle('mc-rl-marcada', marcada);
        const b = l.querySelector('[data-mc-rl-sel]');
        b.setAttribute('aria-pressed', String(marcada));
        b.firstElementChild.className = 'bi ' + (marcada ? 'bi-check-square-fill' : 'bi-square');
      });
    }

    function desenhar(d) {
      raiz.querySelectorAll('[data-mc-rl-aba]').forEach(b => {
        const k = b.dataset.mcRlAba;
        b.setAttribute('aria-selected', String(k === estado.aba));
        const n = b.querySelector('[data-mc-rl-n]');
        const qtd = (d.abas || {})[k] || 0;
        n.textContent = String(qtd);
        n.hidden = qtd === 0;
      });
      estado.paginas = d.paginas || 1;
      estado.p = d.pagina || 1;
      el('[data-mc-rl-faixa]').textContent = d.faixa || BJ_T.t('comercial_relatorio.js.faixa_vazia');
      el('[data-mc-rl-pagina="-1"]').disabled = estado.p <= 1;
      el('[data-mc-rl-pagina="1"]').disabled = estado.p >= estado.paginas;
      const campanhas = d.campanhas || [];
      estado.ids = campanhas.map(c => String(c.id));
      estado.linhas = {};
      campanhas.forEach(c => { estado.linhas[String(c.id)] = c; });
      lista.innerHTML = campanhas.length
        ? campanhas.map(linha).join('')
        : '<div class="mc-rl-vazio"><i class="bi bi-inbox" aria-hidden="true"></i>' + esc(BJ_T.t('comercial_relatorio.js.vazia')) + '</div>';
      lista.removeAttribute('aria-busy');
      desenharSelecao();
    }

    function carregar() {
      const q = new URLSearchParams({ aba: estado.aba, q: estado.q, p: String(estado.p) });
      lista.setAttribute('aria-busy', 'true');
      return pedir(raiz.dataset.urlLista + '?' + q.toString())
        .then(d => { carregado = true; desenhar(d); })
        .catch(() => { lista.removeAttribute('aria-busy'); lista.innerHTML = '<div class="mc-rl-vazio">' + esc(BJ_T.t('comercial_relatorio.js.erro_carregar')) + '</div>'; });
    }

    // ---- Visualizador (dc L502-514) ----
    function texto(campo, valor) {
      const alvo = ver.querySelector('[data-mc-rl-v="' + campo + '"]');
      if (alvo) alvo.textContent = valor == null ? '' : String(valor);
    }

    function preencher(c) {
      estado.aberta = c;
      const de = String(c.de || '');
      const corte = de.indexOf(' <');
      texto('assunto', c.assunto);
      texto('situacao', c.situacao);
      texto('nome', corte > 0 ? de.slice(0, corte) : de);
      texto('endereco', corte > 0 ? de.slice(corte + 1) : '');
      texto('publico', c.publico);
      texto('modelo', c.modelo);
      texto('quando', c.quando);
      ver.querySelector('[data-mc-rl-cancelar]').hidden = !c.cancelavel;
      ver.querySelector('[data-mc-rl-kpis]').innerHTML = (c.kpis || []).map(k => '<div class="mc-rl-kpi"><span class="mc-rl-kpi-rot"><i class="bi ' + esc(k.icone) + '" aria-hidden="true"></i> ' + esc(k.rotulo) + '</span><b class="mc-rl-kpi-v">' + esc(k.valor) + '</b></div>').join('');
      ver.querySelector('[data-mc-rl-html]').srcdoc = c.html || '<p style="font:14px Arial;color:#5b7383;padding:20px">' + esc(BJ_T.t('comercial_relatorio.js.previa_indisponivel')) + '</p>';
      const dest = c.destinatarios || [];
      let corpo = dest.map(p => '<div class="mc-rl-dest"><i class="bi ' + (p.lido ? 'bi-check2-all mc-rl-dest-lido' : 'bi-check2') + '" aria-hidden="true"></i><span class="mc-rl-dest-email">' + esc(p.email) + '</span><span class="mc-rl-dest-st">' + esc(p.estado) + '</span></div>').join('');
      if (!dest.length) corpo = '<div class="mc-rl-dest-vazio">' + esc(c.iniciada ? BJ_T.t('comercial_relatorio.js.lista_nao_registrada') : BJ_T.t('comercial_relatorio.js.lista_ao_comecar')) + '</div>';
      if (c.mais > 0) corpo += '<div class="mc-rl-dest-mais">' + esc(BJ_T.t('comercial_relatorio.js.mais_destinatarios', { n: c.mais })) + '</div>';
      ver.querySelector('[data-mc-rl-dest]').innerHTML = corpo;
    }

    function abrir(id) {
      return pedir(url(raiz.dataset.urlCampanha, id))
        .then(c => { preencher(c); ver.hidden = false; ver.querySelector('.mc-rl-ver-voltar').focus(); })
        .catch(err => avisar(err.message));
    }

    function fechar() {
      ver.hidden = true;
      ver.querySelector('[data-mc-rl-html]').srcdoc = '';
      estado.aberta = null;
    }

    function duplicar() {
      const c = estado.aberta;
      if (!c) return;
      fechar();
      duplicarCampanha(c, BJ_T.t('comercial_relatorio.js.duplicada_visualizador'));
    }

    // O mesmo contrato do visualizador; o menu (dc L704) usa o aviso `duplicada_menu` ("Campanha duplicada: ajuste e envie.").
    function duplicarCampanha(c, mensagem) {
      const detalhe = { assunto: String(c.assunto || '').replace(/^\[TESTE\]\s*/, ''), titulo: c.titulo || c.assunto || '', mensagem: mensagem };
      const tratado = !raiz.dispatchEvent(new CustomEvent('mc:duplicar-campanha', { bubbles: true, cancelable: true, detail: detalhe }));
      if (tratado) return;
      const modo = raiz.closest('.mc[data-modo]');
      let primeiro = null;
      ['assunto', 'titulo'].forEach(campo => {
        const entrada = modo && modo.querySelector('[data-mc-cp-campo="' + campo + '"]');
        if (!entrada) return;
        entrada.value = detalhe[campo];
        entrada.dispatchEvent(new Event('input', { bubbles: true }));
        primeiro = primeiro || entrada;
      });
      if (!primeiro) { avisar(BJ_T.t('comercial_relatorio.js.editor_indisponivel')); return; }
      primeiro.scrollIntoView({ behavior: 'smooth', block: 'center' });
      primeiro.focus({ preventScroll: true });
      avisar(detalhe.mensagem);
    }

    function cancelar() {
      const c = estado.aberta;
      if (!c || !c.cancelavel) return;
      const pergunta = c.estado === 'agendada' || c.estado === 'recorrente'
        ? BJ_T.t('comercial_relatorio.js.confirmar_cancelar')
        : BJ_T.t('comercial_relatorio.js.confirmar_cancelar_fila');
      if (!window.confirm(pergunta)) return;
      pedir(url(raiz.dataset.urlCancelar, c.id), Object.assign(POST(), { body: '{}' }))
        .then(r => { fechar(); avisar(r.mensagem); carregar(); recarregarOutras(instancia); })
        .catch(err => { fechar(); avisar(err.message); });
    }

    // ---- Marcas (F3e): estrela, arquivo e remoção, de cada Master ----
    function marcar(ids, acao) {
      return pedir(raiz.dataset.urlMarcas, Object.assign(POST(), { body: JSON.stringify({ ids: ids.map(Number), acao: acao }) }))
        .then(() => { carregar(); recarregarOutras(instancia); })
        .catch(err => { avisar(err.message); carregar(); recarregarOutras(instancia); });
    }

    function marcarSelecao(acao) {
      const ids = estado.ids.filter(i => estado.sel[i]);
      if (!ids.length) return;
      if (acao === 'remover' && !window.confirm(BJ_T.t('comercial_relatorio.js.confirmar_remover', { n: ids.length }))) return;
      if (acao === 'arquivar' && estado.aba === 'arq') acao = 'desarquivar';
      estado.sel = {};
      marcar(ids, acao);
    }

    function alternarEstrela(id) {
      const c = estado.linhas[id];
      if (c) marcar([id], c.estrela ? 'sem_estrela' : 'estrela');
    }

    // "Reenviar a quem não leu" (dc L704 e L711): só aviso. Sem rastreio (`metricas.rastreiaLeitura`, F3g), o texto de "não rastreou".
    // Do visualizador, como o dc L713: sem rastreio só avisa (o visualizador fica aberto); com rastreio fecha e avisa.
    function reenviar(c, doMenu) {
      const rastreou = !!(c && c.metricas && c.metricas.rastreiaLeitura === true);
      if (doMenu) { avisar(rastreou ? BJ_T.t('comercial_relatorio.js.reenvio_menu_rastreada') : BJ_T.t('comercial_relatorio.js.reenvio_menu_sem_rastreio')); return; }
      if (!rastreou) { avisar(BJ_T.t('comercial_relatorio.js.reenvio_sem_rastreio')); return; }
      fechar();
      avisar(BJ_T.t('comercial_relatorio.js.reenvio_rastreada'));
    }

    // "Exportar destinatários": o CSV vem pronto do servidor (com o BOM); aqui só vira download.
    function exportar(id, formato) {
      fetch(url(raiz.dataset.urlCsv, id) + '?formato=' + encodeURIComponent(formato), { credentials: 'same-origin' })
        .then(r => (r.ok ? r.blob() : r.json().catch(() => ({})).then(d => Promise.reject(new Error(d.erro || BJ_T.t('comercial_relatorio.js.erro_exportar'))))))
        .then(b => {
          const a = document.createElement('a');
          a.href = URL.createObjectURL(b);
          a.download = 'destinatarios-' + id + '.csv';
          document.body.appendChild(a);
          a.click();
          a.remove();
          window.setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        })
        .catch(err => avisar(err.message));
    }

    // ---- Menu do botão direito (dc L504 e L704) ----
    function abrirMenu(id, x, y) {
      const c = estado.linhas[id];
      if (!c) return;
      estado.ctx = c;
      menu.querySelector('[data-mc-rl-ctx-titulo]').textContent = c.nome || '';
      menu.querySelector('[data-mc-rl-ctx-estrela-txt]').textContent = c.estrela ? BJ_T.t('comercial_relatorio.js.estrela_remover') : BJ_T.t('comercial_relatorio.js.estrela_marcar');
      menu.querySelector('[data-mc-rl-ctx-estrela-ic]').className = 'bi ' + (c.estrela ? 'bi-star-fill' : 'bi-star');
      menu.querySelector('[data-mc-rl-ctx-arq-txt]').textContent = estado.aba === 'arq' ? BJ_T.t('comercial_relatorio.js.desarquivar') : BJ_T.t('comercial_relatorio.js.arquivar');
      const caixa = menu.querySelector('.mc-rl-ctx-caixa');
      caixa.style.left = Math.max(0, Math.min(x, window.innerWidth - 270)) + 'px';
      caixa.style.top = Math.max(0, Math.min(y, window.innerHeight - 330)) + 'px';
      menu.hidden = false;
      menu.querySelector('[data-mc-rl-ctx-acao]').focus();
    }

    function fecharMenu() {
      menu.hidden = true;
      estado.ctx = null;
    }

    function acaoDoMenu(acao) {
      const c = estado.ctx;
      fecharMenu();
      if (!c) return;
      const id = String(c.id);
      if (acao === 'abrir') abrir(id);
      else if (acao === 'duplicar') pedir(url(raiz.dataset.urlCampanha, id)).then(d => duplicarCampanha(d, BJ_T.t('comercial_relatorio.js.duplicada_menu'))).catch(err => avisar(err.message));
      else if (acao === 'reenviar') reenviar(c, true);
      else if (acao === 'estrela') marcar([id], c.estrela ? 'sem_estrela' : 'estrela');
      else if (acao === 'arquivar') marcar([id], estado.aba === 'arq' ? 'desarquivar' : 'arquivar');
      else if (acao === 'csv') exportar(id, 'emails');
      else if (acao === 'excluir' && window.confirm(BJ_T.t('comercial_relatorio.js.confirmar_excluir'))) marcar([id], 'remover');
    }

    raiz.addEventListener('click', e => {
      if (e.target.closest('[data-mc-rl-ctx-fechar]')) { fecharMenu(); return; }
      const itemDoMenu = e.target.closest('[data-mc-rl-ctx-acao]');
      if (itemDoMenu) { acaoDoMenu(itemDoMenu.dataset.mcRlCtxAcao); return; }
      const marca = e.target.closest('[data-mc-rl-marcar]');
      if (marca) { marcarSelecao(marca.dataset.mcRlMarcar); return; }
      const aba = e.target.closest('[data-mc-rl-aba]');
      if (aba) { estado.aba = aba.dataset.mcRlAba; estado.p = 1; estado.sel = {}; carregar(); return; }
      if (e.target.closest('[data-mc-rl-atualizar]')) { carregar(); return; }
      const pagina = e.target.closest('[data-mc-rl-pagina]');
      if (pagina) { estado.p = Math.min(estado.paginas, Math.max(1, estado.p + parseInt(pagina.dataset.mcRlPagina, 10))); estado.sel = {}; carregar(); return; }
      if (e.target.closest('[data-mc-rl-sel-todos]')) {
        const todas = estado.ids.length && estado.ids.every(i => estado.sel[i]);
        estado.sel = {};
        if (!todas) estado.ids.forEach(i => { estado.sel[i] = 1; });
        desenharSelecao();
        return;
      }
      if (e.target.closest('[data-mc-rl-fechar]')) { fechar(); return; }
      if (e.target.closest('[data-mc-rl-duplicar]')) { duplicar(); return; }
      if (e.target.closest('[data-mc-rl-cancelar]')) { cancelar(); return; }
      if (e.target.closest('[data-mc-rl-reenviar]')) { reenviar(estado.aberta, false); return; }
      const csv = e.target.closest('[data-mc-rl-csv]');
      if (csv) { if (estado.aberta) exportar(String(estado.aberta.id), csv.dataset.mcRlCsv); return; }
      const l = e.target.closest('[data-mc-rl-id]');
      if (!l) return;
      if (e.target.closest('[data-mc-rl-estrela]')) { alternarEstrela(l.dataset.mcRlId); return; }
      if (e.target.closest('[data-mc-rl-sel]')) {
        const id = l.dataset.mcRlId;
        if (estado.sel[id]) delete estado.sel[id]; else estado.sel[id] = 1;
        desenharSelecao();
        return;
      }
      abrir(l.dataset.mcRlId);
    });

    raiz.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !ver.hidden && menu.hidden) fechar();
    });

    raiz.addEventListener('contextmenu', e => {
      if (e.target.closest('[data-mc-rl-ctx-fechar]')) { e.preventDefault(); fecharMenu(); return; }
      const l = e.target.closest('[data-mc-rl-lista] [data-mc-rl-id]');
      if (!l) return;
      e.preventDefault();
      abrirMenu(l.dataset.mcRlId, e.clientX, e.clientY);
    });

    instancia.menuAberto = () => !menu.hidden;
    instancia.fecharMenu = fecharMenu;
    instancia.recarregarEnviadas = () => { estado.aba = 'env'; estado.p = 1; carregar(); };

    busca.addEventListener('input', () => {
      window.clearTimeout(espera);
      espera = window.setTimeout(() => { estado.q = busca.value.trim(); estado.p = 1; estado.sel = {}; carregar(); }, 300);
    });

    // Carrega quando a seção desta instância abre (Comercial "com" ou Indicações "ind"), ou já aberta pelo hash.
    const painel = document.getElementById('master-painel');
    if (painel) {
      painel.addEventListener('mp:secao', e => { if (e.detail && e.detail.sec === secao && !carregado) carregar(); });
    }
    if (new RegExp('[#&]sec=' + secao + '\\b').test(location.hash)) carregar();
    instancias.push(instancia);
  }

  // Inicia com o documento pronto: a raiz da seção Indicações vem depois deste script no HTML, e o bj-traducao.js
  // (window.BJ_T, os textos) vem no fim do base.
  const iniciarTodas = () => document.querySelectorAll('[data-mc-relatorio]').forEach(iniciar);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciarTodas);
  else iniciarTodas();

  // Esc fecha o menu da instância que o tem aberto (em captura, antes do Esc do visualizador).
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    instancias.forEach(i => { if (i.menuAberto()) { e.stopPropagation(); i.fecharMenu(); } });
  }, true);
  // Depois de cada "Enviar campanha" aceito, em qualquer seção: todas as instâncias recarregam.
  document.addEventListener('mc:campanha-confirmada', () => instancias.forEach(i => i.carregar()));
  // F3f (dc L669): depois do "Enviar teste", a aba Enviadas volta com a linha nova ("Teste").
  document.addEventListener('mc:relatorio-recarregar', () => instancias.forEach(i => i.recarregarEnviadas()));
})();
