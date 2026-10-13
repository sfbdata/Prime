/* =============================================================================
   pasta-metas.js — aba Metas da pasta e modal "Nova meta" (desenho 1.2.3).

   Tudo no navegador, sobre o que o servidor já renderizou:
     1. filtros Abertas · Atrasadas · Concluídas · Todas sobre a lista inteira
        (a lista da pasta não é paginada; o estado de cada linha vem pronto em
        `data-meta-estado`, calculado por `PastaMetasResumoOutput`);
     2. atalhos de prazo do modal (Hoje · Amanhã · +5 úteis · +15 úteis) e o
        texto do dia escolhido — só PREENCHEM o campo; nada fica obrigatório;
     3. aviso de meta aberta com título parecido — alerta, não bloqueia;
     4. "Editar nome" no ⋮: mostra o formulário de renomear sobre o título da
        linha (Enter salva, Esc ou "Cancelar" desiste);
     5. drawer "Relatório da meta": abre pelo clique na linha ou pelo ⋮ "Abrir
        relatório"; ▲▼ navegam, Esc / × / fundo fecham. O conteúdo de cada meta
        veio do servidor; aqui só se escolhe qual mostrar e se monta "Outras
        metas desta pasta" a partir das linhas da lista;
     6. botão direito na linha (PMC-027): abre o MESMO ⋮ da linha no cursor;
     7. meta recém-criada (PMC-044): `?meta=<id>` na URL abre a aba Metas no
        filtro "Abertas" com a linha realçada (destino do POST do modal);
     8. moldura do modal "Nova meta" (PMC-038): "Criar meta" desabilitado com
        "Preencha: …" enquanto falta título ou descrição; Recolher e arrastar
        pela faixa azul;
     9. concluir e reabrir sem recarregar (PMC-031): os MESMOS formulários do ⋮
        e do rodapé do drawer vão por XHR; o servidor devolve a aba renderizada
        de novo e aqui só se trocam os pedaços (cartão, trilho, seções do
        drawer), com o aviso "Meta concluída" / "Meta reaberta" + "Desfazer".

   Concluir, reabrir, renomear e o sino são <form> comuns (POST + CSRF); os menus
   que os contêm são abertos pelo pasta-show.js (`data-ps-pop`). Linha trocada
   pelo item 9 nasce sem os ouvintes do pasta-show.js: o ⋮ e o sino dela são
   abertos aqui (`popoversDasLinhasNovas`), e o X de fechar vem de
   `PsPopovers.religar` (pasta-show.js) logo depois da troca.
   ============================================================================= */
(function () {
    'use strict';

    function iniciar() {
        filtrosDaLista();
        realceDaMetaNova();
        molduraDoModalDeMeta();
        atalhosDePrazo();
        avisoDeTituloRepetido();
        renomearNaLista();
        relatorioDaMeta();
        menuNoBotaoDireito();
        popoversDasLinhasNovas();
        alternarSemRecarregar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    /* Preenchido pelo relatorioDaMeta (item 5): troca as seções do drawer pelas
       que o servidor devolveu no item 9. */
    var trocarSecoesDoRelatorio; // sem valor inicial: iniciar() pode rodar antes desta linha

    /* ── 1. Filtros da lista ──────────────────────────────────────────────── */
    var FILTRO_INICIAL = 'abertas'; // o desenho abre sempre em "Abertas"

    function passaNoFiltro(estado, filtro) {
        if (filtro === 'abertas') { return estado !== 'concluida'; }
        if (filtro === 'atrasadas') { return estado === 'atrasada'; }
        if (filtro === 'concluidas') { return estado === 'concluida'; }
        return true;
    }

    function aplicarFiltro(cartao, filtro) {
        var linhas = cartao.querySelectorAll('.ps-metas-lista > .ps-meta');
        var visiveis = 0;
        Array.prototype.forEach.call(linhas, function (linha) {
            var passa = passaNoFiltro(linha.getAttribute('data-meta-estado'), filtro);
            linha.hidden = !passa;
            if (passa) { visiveis++; }
        });

        Array.prototype.forEach.call(cartao.querySelectorAll('[data-ps-metas-filtro]'), function (b) {
            var ativo = b.getAttribute('data-ps-metas-filtro') === filtro;
            b.classList.toggle('is-ativo', ativo);
            b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
        });

        // Sem meta nenhuma o cartão já mostra o vazio "Nenhuma meta criada";
        // o vazio do FILTRO só existe quando há lista.
        var vazio = cartao.querySelector('.ps-metas-filtro-vazio');
        if (vazio) { vazio.hidden = linhas.length === 0 || visiveis > 0; }
    }

    function filtrosDaLista() {
        var cartao = document.querySelector('[data-ps-metas]');
        if (!cartao) { return; }

        cartao.addEventListener('click', function (e) {
            var botao = e.target.closest('[data-ps-metas-filtro]');
            if (!botao) { return; }
            aplicarFiltro(cartao, botao.getAttribute('data-ps-metas-filtro'));
        });

        aplicarFiltro(cartao, FILTRO_INICIAL);
    }

    /* ── 2. Atalhos de prazo ──────────────────────────────────────────────── */
    function hoje() {
        var d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }

    function paraIso(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    /* Dias úteis = segunda a sexta, como o desenho (feriado: o desenho é omisso).
       "Amanhã" é o PRÓXIMO dia útil — é o que o desenho calcula (util(1)). */
    function somarUteis(n) {
        var d = hoje();
        var k = 0;
        while (k < n) {
            d.setDate(d.getDate() + 1);
            if (d.getDay() % 6 !== 0) { k++; }
        }
        return d;
    }

    function valorDoAtalho(chave) {
        if (chave === 'hoje') { return paraIso(hoje()); }
        if (chave === 'amanha') { return paraIso(somarUteis(1)); }
        if (chave === 'uteis5') { return paraIso(somarUteis(5)); }
        if (chave === 'uteis15') { return paraIso(somarUteis(15)); }
        return '';
    }

    /* "segunda-feira, 12 de outubro, em 7 dias. Cai em fim de semana." */
    function textoDoPrazo(valor) {
        if (!valor) { return 'Escolha a data limite.'; }
        var partes = valor.split('-');
        if (partes.length !== 3) { return 'Escolha a data limite.'; }
        var pz = new Date(+partes[0], +partes[1] - 1, +partes[2]);
        if (isNaN(pz.getTime())) { return 'Escolha a data limite.'; }

        var dias = Math.round((pz - hoje()) / 864e5);
        var texto = pz.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });
        if (dias === 0) { texto += ', hoje'; }
        else if (dias === 1) { texto += ', amanhã'; }
        else if (dias > 1) { texto += ', em ' + dias + ' dias'; }
        else { texto += ', data já passou'; }
        if (pz.getDay() % 6 === 0) { texto += '. Cai em fim de semana.'; }

        return texto;
    }

    function atalhosDePrazo() {
        var campo = document.getElementById('tarefaPrazo');
        if (!campo) { return; }
        var info = document.getElementById('tarefaPrazoInfo');
        var botoes = document.querySelectorAll('[data-ps-prazo-atalho]');

        function atualizar() {
            if (info) { info.textContent = textoDoPrazo(campo.value); }
            Array.prototype.forEach.call(botoes, function (b) {
                var ativo = campo.value !== '' && campo.value === valorDoAtalho(b.getAttribute('data-ps-prazo-atalho'));
                b.classList.toggle('is-ativo', ativo);
                b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
            });
        }

        Array.prototype.forEach.call(botoes, function (b) {
            b.addEventListener('click', function () {
                campo.value = valorDoAtalho(b.getAttribute('data-ps-prazo-atalho'));
                campo.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
        campo.addEventListener('input', atualizar);
        campo.addEventListener('change', atualizar);

        // O modal pode ser reaberto com o campo já preenchido (ou limpo por outro script).
        var modal = document.getElementById('modalCriarTarefa');
        if (modal) { modal.addEventListener('show.bs.modal', atualizar); }

        atualizar();
    }

    /* ── 3. Aviso de título repetido ──────────────────────────────────────── */
    /* Mesma regra do desenho: palavras com mais de 3 letras, sem acento; título
       com ao menos 2 delas; meta ABERTA da pasta com metade ou mais em comum. */
    function palavras(texto) {
        return String(texto || '')
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .split(/[^a-z0-9]+/)
            .filter(function (w) { return w.length > 3; });
    }

    function metaParecida(titulo) {
        var tw = palavras(titulo);
        if (tw.length < 2) { return null; }

        var melhor = null;
        var melhorNota = 0;
        var linhas = document.querySelectorAll('[data-ps-metas] .ps-metas-lista > .ps-meta');
        Array.prototype.forEach.call(linhas, function (linha) {
            if (linha.getAttribute('data-meta-estado') === 'concluida') { return; }
            var mw = palavras(linha.getAttribute('data-meta-titulo'));
            var comum = tw.filter(function (w) { return mw.indexOf(w) !== -1; }).length;
            var nota = comum / Math.max(tw.length, mw.length);
            if (nota >= 0.5 && nota > melhorNota) {
                melhor = linha;
                melhorNota = nota;
            }
        });

        return melhor;
    }

    function destacarNaLista(linha) {
        var cartao = document.querySelector('[data-ps-metas]');
        if (cartao) { aplicarFiltro(cartao, 'abertas'); }

        var aba = document.getElementById('tarefas-tab');
        if (aba && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(aba).show(); }

        linha.classList.remove('ps-meta--destaque');
        void linha.offsetWidth; // reinicia a animação se já estava destacada
        linha.classList.add('ps-meta--destaque');
        setTimeout(function () { linha.classList.remove('ps-meta--destaque'); }, 4200);
        linha.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function avisoDeTituloRepetido() {
        var campo = document.getElementById('tarefaTitulo');
        var aviso = document.getElementById('tarefaTituloRepetido');
        if (!campo || !aviso) { return; }
        var texto = aviso.querySelector('.ps-nm-dup-tx');
        var abrir = aviso.querySelector('.ps-nm-dup-abrir');
        var atual = null;

        function verificar() {
            atual = metaParecida(campo.value);
            if (!atual) {
                aviso.hidden = true;
                return;
            }
            var prazo = atual.getAttribute('data-meta-prazo');
            texto.textContent = 'Já existe a meta aberta "' + atual.getAttribute('data-meta-titulo') + '"'
                + (prazo ? ' (prazo ' + prazo + ')' : '')
                + '. Atualize a existente em vez de criar outra.';
            aviso.hidden = false;
        }

        campo.addEventListener('input', verificar);

        abrir.addEventListener('click', function () {
            if (!atual) { return; }
            var linha = atual;
            var modal = document.getElementById('modalCriarTarefa');
            var instancia = modal && window.bootstrap ? bootstrap.Modal.getInstance(modal) : null;
            if (instancia) {
                modal.addEventListener('hidden.bs.modal', function aoFechar() {
                    modal.removeEventListener('hidden.bs.modal', aoFechar);
                    destacarNaLista(linha);
                });
                instancia.hide();
            } else {
                destacarNaLista(linha);
            }
        });

        var modal = document.getElementById('modalCriarTarefa');
        if (modal) { modal.addEventListener('show.bs.modal', verificar); }
    }

    /* ── 4. Renomear na lista ─────────────────────────────────────────────── */
    /* O formulário é filho da linha (fora da âncora "abrir a meta"); aqui ele é
       colocado exatamente sobre o título: mesma esquerda e largura do corpo da
       linha, o título fica invisível e reserva a altura do campo. Quem grava é o
       POST normal do formulário (Enter no campo submete). */
    function posicionarEditor(linha, form) {
        var corpo = linha.querySelector('.ps-meta-corpo');
        var titulo = linha.querySelector('.ps-meta-titulo');
        if (!corpo || !titulo) { return; }
        var base = linha.getBoundingClientRect();
        var c = corpo.getBoundingClientRect();
        var t = titulo.getBoundingClientRect();
        form.style.left = (c.left - base.left) + 'px';
        form.style.width = c.width + 'px';
        form.style.top = (t.top - base.top) + 'px';
    }

    function renomearNaLista() {
        var cartao = document.querySelector('[data-ps-metas]');
        if (!cartao) { return; }
        var aberta = null;

        function fechar() {
            if (!aberta) { return; }
            var form = aberta.querySelector('.ps-meta-renomear');
            var campo = form.querySelector('.ps-meta-ren-campo');
            campo.value = campo.defaultValue; // desistir devolve o nome de antes
            form.hidden = true;
            aberta.classList.remove('is-renomeando');
            aberta = null;
        }

        function abrir(linha) {
            fechar();
            var form = linha.querySelector('.ps-meta-renomear');
            if (!form) { return; }
            aberta = linha;
            linha.classList.add('is-renomeando');
            form.hidden = false;
            posicionarEditor(linha, form);
            var campo = form.querySelector('.ps-meta-ren-campo');
            campo.focus();
            campo.select();
        }

        cartao.addEventListener('click', function (e) {
            var item = e.target.closest('[data-ps-meta-renomear]');
            if (item) {
                var linha = item.closest('.ps-meta');
                if (linha) { abrir(linha); }
                return;
            }
            if (e.target.closest('.ps-meta-ren-cancelar')) { fechar(); }
        });

        cartao.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !e.target.closest('.ps-meta-renomear')) { return; }
            e.preventDefault();
            fechar();
        });

        // O nome vazio não sai do navegador (`required`), mas espaço puro sim:
        // o servidor recusa; aqui só se evita a ida e volta.
        cartao.addEventListener('submit', function (e) {
            var form = e.target.closest('.ps-meta-renomear');
            if (!form) { return; }
            var campo = form.querySelector('.ps-meta-ren-campo');
            if (campo.value.trim() === '') {
                e.preventDefault();
                campo.value = '';
                campo.reportValidity();
            }
        });

        window.addEventListener('resize', function () {
            if (aberta) { posicionarEditor(aberta, aberta.querySelector('.ps-meta-renomear')); }
        });

        // Trocar de filtro esconde linhas: o editor de uma linha escondida é largado.
        cartao.addEventListener('click', function (e) {
            if (e.target.closest('[data-ps-metas-filtro]') && aberta && aberta.hidden) { fechar(); }
        });
    }

    /* ── 5. Drawer "Relatório da meta" ────────────────────────────────────── */
    /* Desenho (dc L.1464-1508, `metaVals`): "N de M" e ▲▼ seguem a ordem da
       lista inteira (não o filtro); "Outras metas desta pasta" lista as demais,
       cada uma leva ao relatório dela. */
    function relatorioDaMeta() {
        var drawer = document.getElementById('psMetaRelatorio');
        var fundo = document.getElementById('psMetaRelatorioFundo');
        var cartao = document.querySelector('[data-ps-metas]');
        if (!drawer || !fundo || !cartao) { return; }

        // Fora do painel da aba: ancestral com transform prenderia o `fixed`.
        document.body.appendChild(fundo);
        document.body.appendChild(drawer);

        var secoes = [];
        var ordem = [];
        function lerSecoes() {
            secoes = Array.prototype.slice.call(drawer.querySelectorAll('.ps-mrel-meta[data-meta-rel]'));
            ordem = secoes.map(function (s) { return s.getAttribute('data-meta-rel'); });
        }
        lerSecoes();
        var pos = drawer.querySelector('[data-ps-mrel-pos]');
        var anterior = drawer.querySelector('[data-ps-mrel-anterior]');
        var proxima = drawer.querySelector('[data-ps-mrel-proxima]');
        var botaoFechar = drawer.querySelector('.ps-mrel-fechar');
        var atual = null;
        var origem = null;

        function linhaDa(id) {
            return cartao.querySelector('.ps-metas-lista > .ps-meta[data-meta-id="' + id + '"]');
        }

        function montarOutras(secao, id) {
            var bloco = secao.querySelector('[data-ps-mrel-outras-secao]');
            var lista = secao.querySelector('[data-ps-mrel-outras]');
            if (!bloco || !lista) { return; }
            lista.textContent = '';
            ordem.forEach(function (outro) {
                if (outro === id) { return; }
                var linha = linhaDa(outro);
                if (!linha) { return; }
                var status = linha.querySelector('.ps-meta-status');

                var botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'ps-mrel-outra ps-mrel-outra--' + (linha.getAttribute('data-meta-estado') || 'aberta');
                botao.setAttribute('data-ps-mrel-ir', outro);

                var barra = document.createElement('span');
                barra.className = 'ps-mrel-outra-barra';
                barra.setAttribute('aria-hidden', 'true');
                var titulo = document.createElement('span');
                titulo.className = 'ps-mrel-outra-titulo';
                titulo.textContent = linha.getAttribute('data-meta-titulo') || '';
                var selo = document.createElement('span');
                selo.className = 'ps-mrel-outra-status';
                selo.textContent = status ? status.textContent.trim() : '';

                botao.appendChild(barra);
                botao.appendChild(titulo);
                botao.appendChild(selo);
                lista.appendChild(botao);
            });
            bloco.hidden = lista.children.length === 0;
        }

        function mostrar(id) {
            var i = ordem.indexOf(id);
            if (i === -1) { return; }
            atual = id;
            secoes.forEach(function (s, k) {
                s.hidden = k !== i;
                if (k === i) {
                    montarOutras(s, id);
                    var corpo = s.querySelector('.ps-mrel-corpo');
                    if (corpo) { corpo.scrollTop = 0; }
                }
            });
            pos.textContent = (i + 1) + ' de ' + ordem.length;
            anterior.disabled = i <= 0;
            proxima.disabled = i >= ordem.length - 1;
        }

        function abrir(id, gatilho) {
            if (ordem.indexOf(id) === -1) { return; }
            origem = gatilho || document.activeElement;
            mostrar(id);
            fundo.hidden = false;
            drawer.hidden = false;
            botaoFechar.focus();
        }

        function fechar() {
            if (drawer.hidden) { return; }
            drawer.hidden = true;
            fundo.hidden = true;
            atual = null;
            if (origem && document.contains(origem) && typeof origem.focus === 'function') { origem.focus(); }
            origem = null;
        }

        cartao.addEventListener('click', function (e) {
            var item = e.target.closest('[data-ps-meta-relatorio]');
            if (item) {
                var linhaDoItem = item.closest('.ps-meta');
                abrir(item.getAttribute('data-ps-meta-relatorio'), linhaDoItem ? linhaDoItem.querySelector('.ps-pop-wrap.ps-meta-acoes > .ps-meta-menu-btn') : null);
                return;
            }

            // Clique comum na linha abre o relatório (desenho). Ctrl/⌘/Shift/meio
            // continuam abrindo a página da meta, como qualquer link.
            var link = e.target.closest('a.ps-meta-abrir');
            if (!link || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
            var linha = link.closest('.ps-meta');
            if (!linha || linha.classList.contains('is-renomeando')) { return; }
            e.preventDefault();
            abrir(linha.getAttribute('data-meta-id'), link);
        });

        drawer.addEventListener('click', function (e) {
            if (e.target.closest('[data-ps-mrel-fechar]')) { fechar(); return; }
            if (e.target.closest('[data-ps-mrel-anterior]')) { mostrar(ordem[ordem.indexOf(atual) - 1]); return; }
            if (e.target.closest('[data-ps-mrel-proxima]')) { mostrar(ordem[ordem.indexOf(atual) + 1]); return; }
            var ir = e.target.closest('[data-ps-mrel-ir]');
            if (ir) { mostrar(ir.getAttribute('data-ps-mrel-ir')); }
        });
        fundo.addEventListener('click', fechar);

        // Item 9: o servidor mandou a aba de novo. As seções do drawer são trocadas
        // pelas novas (o cabeçalho e os ouvintes ficam); aberto, ele continua na
        // MESMA meta, com o foco no botão que a substituiu.
        trocarSecoesDoRelatorio = function (novoDrawer) {
            secoes.forEach(function (s) { s.remove(); });
            Array.prototype.forEach.call(novoDrawer.querySelectorAll('.ps-mrel-meta[data-meta-rel]'), function (s) {
                drawer.appendChild(s);
            });
            lerSecoes();
            if (atual === null || drawer.hidden) { return; }
            if (ordem.indexOf(atual) === -1) {
                fechar();
                return;
            }
            mostrar(atual);
            var alvo = secoes[ordem.indexOf(atual)].querySelector('.ps-mrel-alternar') || botaoFechar;
            alvo.focus();
        };

        document.addEventListener('keydown', function (e) {
            if (drawer.hidden) { return; }
            if (e.key === 'Escape') {
                e.preventDefault();
                fechar();
                return;
            }
            // O foco não sai do drawer enquanto ele está aberto (aria-modal).
            if (e.key !== 'Tab') { return; }
            var focaveis = Array.prototype.filter.call(
                drawer.querySelectorAll('button, a[href], input, [tabindex]:not([tabindex="-1"])'),
                function (el) { return !el.disabled && el.offsetParent !== null; }
            );
            if (focaveis.length === 0) { return; }
            var primeiro = focaveis[0];
            var ultimo = focaveis[focaveis.length - 1];
            if (e.shiftKey && document.activeElement === primeiro) {
                e.preventDefault();
                ultimo.focus();
            } else if (!e.shiftKey && document.activeElement === ultimo) {
                e.preventDefault();
                primeiro.focus();
            } else if (!drawer.contains(document.activeElement)) {
                e.preventDefault();
                primeiro.focus();
            }
        });
    }

    /* ── 9. Concluir e reabrir sem recarregar (PMC-031) ───────────────────── */
    /* (Fica antes do 6 de propósito: o PastaMenuBotaoDireitoJsTest lê do 6 ao
       fim do arquivo e exige que o botão direito não monte nada em JS.) */
    /* Desenho (dc `metaVals.alternar`, L.3548; aviso L.1509-1510): marcar como
       concluída ou reabrir troca a linha na hora e mostra "Meta concluída" /
       "Meta reaberta" com "Desfazer" por 4,2 s. Aqui os formulários de sempre
       (⋮ da linha e rodapé do drawer) vão por XHR para as MESMAS rotas, com o
       mesmo CSRF; o servidor responde com a aba renderizada de novo e nada é
       recontado no navegador — só se trocam o conteúdo do cartão, o trilho e as
       seções do drawer, e o filtro escolhido é reaplicado. "Desfazer" manda o
       formulário OPOSTO da linha nova (reabrir depois de concluir e vice-versa),
       pela mesma guarda. Sem fetch, o formulário segue o POST + redirect. */
    var FORMS_DE_ALTERNAR = 'form.ps-meta-concluir, form.ps-meta-reabrir, form.ps-mrel-alternar-form';
    var AVISO_MS = 4200; // dc `aviso`: o aviso some em 4,2 s

    var avisoEl = null;
    var avisoTexto = null;
    var avisoBotao = null;
    var avisoTimer = null;
    var avisoDesfazer = null;

    function esconderAviso() {
        clearTimeout(avisoTimer);
        avisoDesfazer = null;
        if (avisoEl) { avisoEl.hidden = true; }
    }

    function avisar(texto, desfazer) {
        if (!avisoEl) {
            avisoEl = document.createElement('div');
            avisoEl.className = 'ps-metas-aviso';
            avisoEl.setAttribute('role', 'status');
            avisoEl.setAttribute('aria-live', 'polite');
            avisoEl.hidden = true;
            avisoTexto = document.createElement('span');
            avisoBotao = document.createElement('button');
            avisoBotao.type = 'button';
            avisoBotao.className = 'ps-metas-aviso-desfazer';
            avisoBotao.textContent = 'Desfazer';
            avisoBotao.addEventListener('click', function () {
                var acao = avisoDesfazer;
                esconderAviso();
                if (acao) { acao(); }
            });
            avisoEl.appendChild(avisoTexto);
            avisoEl.appendChild(avisoBotao);
            document.body.appendChild(avisoEl);
        }

        clearTimeout(avisoTimer);
        avisoTexto.textContent = texto;
        avisoDesfazer = desfazer || null;
        avisoBotao.hidden = avisoDesfazer === null;
        // Esconder e mostrar de novo reinicia a animação de entrada.
        avisoEl.hidden = true;
        void avisoEl.offsetWidth;
        avisoEl.hidden = false;
        avisoTimer = setTimeout(esconderAviso, AVISO_MS);
    }

    /* Linha trocada nasce sem os ouvintes do pasta-show.js (que liga cada
       `data-ps-pop` uma vez, no carregamento). O ⋮ e o sino dela recebem
       `data-ps-pop-local` e são abertos aqui, com as mesmas regras: abrir um
       fecha os outros, clique fora, item do menu ou Esc fecham. */
    function painelDe(gatilho) {
        return document.getElementById(gatilho.getAttribute('data-ps-pop') || '');
    }

    function fecharPopoversNovos(cartao, exceto) {
        Array.prototype.forEach.call(cartao.querySelectorAll('[data-ps-pop-local]'), function (g) {
            var painel = painelDe(g);
            if (!painel || painel === exceto) { return; }
            painel.classList.remove('is-aberto');
            g.setAttribute('aria-expanded', 'false');
        });
    }

    function popoversDasLinhasNovas() {
        var cartao = document.querySelector('[data-ps-metas]');
        if (!cartao) { return; }

        cartao.addEventListener('click', function (e) {
            var gatilho = e.target.closest('[data-ps-pop-local]');
            if (!gatilho) { return; }
            e.stopPropagation(); // o clique no gatilho não é "clique fora"
            var painel = painelDe(gatilho);
            if (!painel) { return; }
            var vaiAbrir = !painel.classList.contains('is-aberto');
            document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
            fecharPopoversNovos(cartao, null);
            // PAS-022b: como no `popovers()` do pasta-show.js, o menu arrastado reabre
            // junto do gatilho.
            if (vaiAbrir && window.PsArrastar) { window.PsArrastar.zerar(painel); }
            painel.classList.toggle('is-aberto', vaiAbrir);
            gatilho.setAttribute('aria-expanded', vaiAbrir ? 'true' : 'false');
        });

        document.addEventListener('click', function (e) {
            if (e.target.closest('.ps-pop') && !e.target.closest('.ps-pop-item')) { return; }
            fecharPopoversNovos(cartao, null);
        });
        document.addEventListener('ps:fechar-popovers', function () { fecharPopoversNovos(cartao, null); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fecharPopoversNovos(cartao, null); }
        });
    }

    function aplicarAbaNova(cartao, html) {
        var molde = document.createElement('template');
        molde.innerHTML = html;
        var novo = molde.content;
        var novoCartao = novo.querySelector('[data-ps-metas]');
        if (!novoCartao) { return false; }

        var ativo = cartao.querySelector('[data-ps-metas-filtro].is-ativo');
        var filtro = ativo ? ativo.getAttribute('data-ps-metas-filtro') : FILTRO_INICIAL;

        document.dispatchEvent(new CustomEvent('ps:fechar-popovers'));
        // O elemento do cartão fica (os ouvintes dos itens 1 a 6 moram nele);
        // só o conteúdo é trocado.
        while (cartao.firstChild) { cartao.removeChild(cartao.firstChild); }
        while (novoCartao.firstChild) { cartao.appendChild(novoCartao.firstChild); }
        Array.prototype.forEach.call(cartao.querySelectorAll('[data-ps-pop]'), function (g) {
            g.setAttribute('data-ps-pop-local', '1');
        });
        // PAS-022b: o X de fechar das janelinhas novas (o do carregamento não as alcança).
        if (window.PsPopovers) { window.PsPopovers.religar(cartao); }

        var grade = cartao.parentNode;
        var trilho = grade ? grade.querySelector(':scope > .ps-trilho') : null;
        var novoTrilho = novo.querySelector('.ps-trilho');
        if (trilho && novoTrilho) { trilho.replaceWith(novoTrilho); }

        var novoDrawer = novo.querySelector('#psMetaRelatorio');
        if (novoDrawer && trocarSecoesDoRelatorio) { trocarSecoesDoRelatorio(novoDrawer); }

        aplicarFiltro(cartao, filtro);

        return true;
    }

    function metaDoFormulario(form) {
        var dono = form.closest('[data-meta-id]') || form.closest('[data-meta-rel]');
        if (!dono) { return null; }

        return dono.getAttribute('data-meta-id') || dono.getAttribute('data-meta-rel');
    }

    function liberar(form) {
        form.removeAttribute('data-ps-enviando');
        Array.prototype.forEach.call(form.querySelectorAll('button'), function (b) { b.disabled = false; });
    }

    /* Depois da troca, o botão que tinha o foco (item do ⋮) não existe mais: o
       foco vai para o ⋮ da mesma linha, se ela continua à vista no filtro, ou
       para o filtro ativo. No drawer quem cuida é o `trocarSecoesDoRelatorio`. */
    function devolverFoco(cartao, id) {
        var linha = cartao.querySelector('.ps-metas-lista > .ps-meta[data-meta-id="' + id + '"]');
        var alvo = linha && !linha.hidden ? linha.querySelector('.ps-meta-acoes > .ps-meta-menu-btn') : null;
        if (!alvo) { alvo = cartao.querySelector('[data-ps-metas-filtro].is-ativo'); }
        if (alvo) { alvo.focus(); }
    }

    function enviarAlternancia(cartao, form, ehDesfazer) {
        if (form.hasAttribute('data-ps-enviando')) { return; }
        form.setAttribute('data-ps-enviando', '1');
        Array.prototype.forEach.call(form.querySelectorAll('button'), function (b) { b.disabled = true; });

        var id = metaDoFormulario(form);
        var drawer = document.getElementById('psMetaRelatorio');
        var doDrawer = !!(drawer && drawer.contains(form));

        // PMC-061: o "Desfazer" de um reabrir manda o concluir com `desfazer=1`, e o servidor
        // devolve a data de conclusão ORIGINAL (guardada por ele no reabrir). Nenhuma data sai
        // daqui; sem a flag, o concluir gravaria a data de agora.
        var corpo = new FormData(form);
        if (ehDesfazer && form.matches('form.ps-meta-concluir')) { corpo.append('desfazer', '1'); }

        fetch(form.action, {
            method: 'POST',
            body: corpo,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        })
            .then(function (r) {
                return r.json().then(
                    function (d) { return { ok: r.ok, dados: d }; },
                    function () { return { ok: false, dados: null }; }
                );
            })
            .then(function (res) {
                var d = res.dados;
                // A recusa da pasta excluída (lápide) vem em JSON com `mensagem`; a
                // guarda (403/404) e o CSRF não: caem no texto genérico.
                if (!res.ok || !d || d.sucesso !== true) {
                    liberar(form);
                    avisar((d && d.mensagem) || 'Não foi possível alterar a meta. Recarregue a página e tente de novo.');
                    return;
                }
                // SEC-015: quem não pode VER a pasta recebe só o resultado da própria meta, sem a
                // aba (`html`). Nada a trocar aqui: a página é recarregada pelo servidor.
                if (typeof d.html !== 'string') {
                    window.location.reload();
                    return;
                }
                if (!aplicarAbaNova(cartao, d.html)) {
                    window.location.reload();
                    return;
                }
                if (!doDrawer && id) { devolverFoco(cartao, id); }
                if (ehDesfazer) {
                    avisar('Desfeito');
                    return;
                }
                avisar(d.mensagem, d.mudou && id ? function () { desfazer(cartao, id); } : null);
            })
            .catch(function () {
                liberar(form);
                avisar('Erro de comunicação. Recarregue a página para conferir a meta.');
            });
    }

    function desfazer(cartao, id) {
        var linha = cartao.querySelector('.ps-metas-lista > .ps-meta[data-meta-id="' + id + '"]');
        var oposto = linha ? linha.querySelector('.ps-meta-acoes form.ps-meta-concluir, .ps-meta-acoes form.ps-meta-reabrir') : null;
        if (!oposto) {
            avisar('Não foi possível desfazer. Recarregue a página.');
            return;
        }
        enviarAlternancia(cartao, oposto, true);
    }

    function alternarSemRecarregar() {
        var cartao = document.querySelector('[data-ps-metas]');
        if (!cartao || !window.fetch || !window.FormData || !('content' in document.createElement('template'))) { return; }

        // No document: o drawer (com o rodapé "Marcar como concluída") mora no <body>.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.matches || !form.matches(FORMS_DE_ALTERNAR)) { return; }
            var drawer = document.getElementById('psMetaRelatorio');
            if (!cartao.contains(form) && !(drawer && drawer.contains(form))) { return; }
            e.preventDefault();
            enviarAlternancia(cartao, form, false);
        });
    }

    /* ── 6. Botão direito na linha da meta (PMC-027) ─────────────────────── */
    /* Desenho (dc L.1430, `onContextMenu={m.menuCtx}`): o botão direito na linha
       abre o mesmo menu do ⋮, no cursor. Nada é duplicado: quem abre e fecha o
       popover continua sendo o pasta-show.js (`data-ps-pop`) — aqui o ⋮ é
       acionado e o painel só muda de LUGAR. O ⋮ comum devolve o painel ao canto
       de sempre. Teclado e toque seguem pelo ⋮. Botões, campos, o editor do nome
       e o próprio menu ficam com o menu nativo, e também quem selecionou texto. */
    function menuNoBotaoDireito() {
        var cartao = document.querySelector('[data-ps-metas]');
        if (!cartao) { return; }

        function voltarAoCanto(menu) {
            menu.style.left = '';
            menu.style.top = '';
            menu.style.right = '';
        }

        // Coordenadas relativas ao `.ps-pop-wrap` (o painel é absoluto ali dentro),
        // empurradas para dentro da janela quando não cabem.
        function noCursor(menu, x, y) {
            var base = (menu.offsetParent || menu.parentNode).getBoundingClientRect();
            menu.style.right = 'auto';
            menu.style.left = (x - base.left) + 'px';
            menu.style.top = (y - base.top) + 'px';
            var r = menu.getBoundingClientRect();
            if (r.right > window.innerWidth - 8) { menu.style.left = (x - base.left - r.width) + 'px'; }
            if (r.bottom > window.innerHeight - 8) { menu.style.top = (y - base.top - r.height) + 'px'; }
        }

        // Captura: roda antes do clique do ⋮ que o pasta-show.js escuta.
        cartao.addEventListener('click', function (e) {
            var gatilho = e.target.closest('.ps-meta-acoes > .ps-meta-menu-btn');
            if (!gatilho) { return; }
            var menu = document.getElementById(gatilho.getAttribute('data-ps-pop') || '');
            if (menu) { voltarAoCanto(menu); }
        }, true);

        cartao.addEventListener('contextmenu', function (e) {
            var linha = e.target.closest('article.ps-meta[data-ps-menu-contexto]');
            if (!linha || linha.classList.contains('is-renomeando')) { return; }
            if (e.target.closest('button, input, textarea, select, form, .ps-pop')) { return; }
            var selecao = window.getSelection ? String(window.getSelection()) : '';
            if (selecao.trim() !== '') { return; }

            var menu = document.getElementById(linha.getAttribute('data-ps-menu-contexto'));
            var gatilho = linha.querySelector('.ps-meta-acoes > .ps-meta-menu-btn');
            if (!menu || !gatilho) { return; }

            e.preventDefault();
            if (!menu.classList.contains('is-aberto')) { gatilho.click(); }
            // Sem coordenada (tecla de menu): fica no canto do ⋮, como no clique.
            if (e.clientX > 0 || e.clientY > 0) { noCursor(menu, e.clientX, e.clientY); }
        });
    }

    /* ── 7. Meta recém-criada (PMC-044) ───────────────────────────────────── */
    /* Desenho (dc `salvar` do modal): depois de criar, a pasta fica na aba Metas,
       filtro "Abertas", com a meta nova realçada. O modal publica por XHR e a
       lista (numeração, contadores, drawer, menus ⋮) é do servidor, então o
       destino recarrega a pasta com `?meta=<id>#tarefas`; aqui só se acha a linha
       e se realça pelo MESMO caminho do "Abrir meta existente". O parâmetro sai
       da URL para um F5 não realçar de novo. */
    function realceDaMetaNova() {
        var params = new URLSearchParams(window.location.search);
        var id = params.get('meta');
        if (!id || !/^\d+$/.test(id)) { return; }

        params.delete('meta');
        if (window.history && window.history.replaceState) {
            var busca = params.toString();
            window.history.replaceState(null, '', window.location.pathname + (busca ? '?' + busca : '') + window.location.hash);
        }

        var linha = document.querySelector('[data-ps-metas] .ps-metas-lista > .ps-meta[data-meta-id="' + id + '"]');
        if (linha) { destacarNaLista(linha); }
    }

    /* ── 8. Moldura do modal "Nova meta" (PMC-038) ────────────────────────── */
    /* Desenho (dc `salvarSt`, `faltaTxt`, `recolher`, `arrastar`): o "Criar meta"
       fica desabilitado, com "Preencha: …" na dica, enquanto falta campo; pronto,
       a dica é "Criar meta e notificar os responsáveis". Só título e descrição
       são cobrados: prazo e responsáveis obrigatórios são D-META (decisão
       pendente), e o servidor só exige os dois. Os outros scripts preenchem os
       campos por `value` (sem evento), então a conta refaz ao abrir o modal.
       Recolher (botão ou duplo clique na faixa) esconde corpo e rodapé; a faixa
       arrasta a janela, sempre com 48px dela à vista. Fechar desfaz os dois. */
    var FALTA_NENHUMA = 'Criar meta e notificar os responsáveis';

    function faltaNaMeta(titulo, descricao) {
        var falta = [];
        if (!titulo || titulo.value.trim() === '') { falta.push('título'); }
        if (!descricao || descricao.value.trim() === '') { falta.push('descrição'); }
        return falta;
    }

    function molduraDoModalDeMeta() {
        var modal = document.getElementById('modalCriarTarefa');
        var botao = document.getElementById('btnEnviarTarefa');
        if (!modal || !botao) { return; }
        var titulo = document.getElementById('tarefaTitulo');
        var descricao = document.getElementById('tarefaDescricao');
        var janela = modal.querySelector('.modal-dialog');
        var alca = modal.querySelector('[data-ps-nm-alca]');
        var recolher = modal.querySelector('[data-ps-nm-recolher]');

        function conferir() {
            var falta = faltaNaMeta(titulo, descricao);
            botao.disabled = falta.length > 0;
            botao.title = falta.length > 0 ? 'Preencha: ' + falta.join(', ') : FALTA_NENHUMA;
        }

        [titulo, descricao].forEach(function (el) {
            if (el) { el.addEventListener('input', conferir); }
        });
        modal.addEventListener('show.bs.modal', conferir);
        modal.addEventListener('shown.bs.modal', conferir);
        conferir();

        // C6-PMC-01: a janela não é modal (sem fundo e sem trava de foco), então o Bootstrap nem
        // leva o foco para dentro nem ouve o Esc fora dela. Foco no título ao abrir; Esc no
        // documento fecha (o desenho tem `nm.tecla`), salvo quando outra janela modal está aberta
        // — essa trata o próprio Esc. `aria-modal` sai: a página atrás continua ativa.
        modal.addEventListener('shown.bs.modal', function () {
            modal.removeAttribute('aria-modal');
            if (titulo) { titulo.focus(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !modal.classList.contains('show')) { return; }
            if (document.querySelector('.modal.show:not(#modalCriarTarefa)')) { return; }
            if (window.bootstrap) { window.bootstrap.Modal.getOrCreateInstance(modal).hide(); }
        });

        function alternarRecolhido() {
            var rec = !janela.classList.contains('is-recolhido');
            janela.classList.toggle('is-recolhido', rec);
            if (!recolher) { return; }
            var dica = rec ? 'Expandir' : 'Recolher (continue vendo a lista)';
            recolher.setAttribute('aria-label', dica);
            recolher.title = dica;
            var icone = recolher.querySelector('i');
            if (icone) { icone.className = rec ? 'bi bi-chevron-bar-expand' : 'bi bi-dash-lg'; }
        }

        if (recolher) {
            recolher.addEventListener('click', function (e) {
                e.stopPropagation();
                alternarRecolhido();
            });
        }

        if (alca && janela) {
            alca.addEventListener('dblclick', function (e) {
                if (e.target.closest('button')) { return; }
                alternarRecolhido();
            });

            alca.addEventListener('pointerdown', function (e) {
                if (e.button !== 0 || e.target.closest('button')) { return; }
                e.preventDefault();
                var r = janela.getBoundingClientRect();
                var x0 = parseFloat(janela.style.left) || 0;
                var y0 = parseFloat(janela.style.top) || 0;
                var dx = e.clientX - r.left;
                var dy = e.clientY - r.top;
                try { alca.setPointerCapture(e.pointerId); } catch (x) { /* sem captura, segue */ }

                function mover(m) {
                    var x = Math.min(window.innerWidth - 48, Math.max(48 - r.width, m.clientX - dx));
                    var y = Math.min(window.innerHeight - 30, Math.max(0, m.clientY - dy));
                    janela.style.left = (x0 + x - r.left) + 'px';
                    janela.style.top = (y0 + y - r.top) + 'px';
                }
                function soltar() {
                    alca.removeEventListener('pointermove', mover);
                    alca.removeEventListener('pointerup', soltar);
                    alca.removeEventListener('pointercancel', soltar);
                }
                alca.addEventListener('pointermove', mover);
                alca.addEventListener('pointerup', soltar);
                alca.addEventListener('pointercancel', soltar);
            });
        }

        modal.addEventListener('hidden.bs.modal', function () {
            if (janela) {
                janela.style.left = '';
                janela.style.top = '';
                if (janela.classList.contains('is-recolhido')) { alternarRecolhido(); }
            }
        });
    }
}());
