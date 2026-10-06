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
        linha (Enter salva, Esc ou "Cancelar" desiste).

   Concluir, reabrir, renomear e o sino são <form> comuns (POST + CSRF); os menus
   que os contêm são abertos pelo pasta-show.js (`data-ps-pop`).
   ============================================================================= */
(function () {
    'use strict';

    function iniciar() {
        filtrosDaLista();
        atalhosDePrazo();
        avisoDeTituloRepetido();
        renomearNaLista();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

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
}());
