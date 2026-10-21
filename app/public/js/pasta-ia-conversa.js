/* =============================================================================
   BlueJus IA: aba "Perguntar" do drawer da pasta (spec inteligencia-drawer-da-
   pasta, F3; desenho dc L5920-5938 e L5974-5977).

     1. carrega a conversa (GET `inteligencia_conversa_listar`) na primeira vez
        que a aba aparece e a troca inteira a cada recarga;
     2. envia a pergunta (POST `inteligencia_conversa_perguntar`, campo `texto`
        e o `_token` do drawer) pelo botão, pelo Enter e pelas sugestões; o
        retorno é honesto: 202 em andamento, 200 com aviso (já havia uma em
        andamento: o texto digitado fica no campo), 409/422/429 com o motivo;
        motivo de disponibilidade trava campo e botão até a página recarregar;
     3. polling da conversa enquanto houver pergunta em andamento
        (`data-ia-em-andamento`): 2 s, 5 s depois de 30 s, 10 s depois de
        2 min; PAUSA com a página oculta ou o drawer fechado;
     4. botão enviar desabilitado com o campo vazio, com pergunta em andamento
        ou enviando; sugestões desabilitadas no mesmo caso.

   A troca de aba e o mostrar/esconder do rodapé são do pasta-ia-agentes.js
   (o rodapé tem `data-ia-aba-painel="perguntar"`). Nenhuma resposta nasce
   aqui: o texto da IA vem só do servidor, escapado pelo Twig.
   ============================================================================= */
(function () {
    'use strict';

    var BACKOFF = [[30000, 2000], [120000, 5000], [Infinity, 10000]];
    var LIMITE_MS = 600000;
    var MOTIVOS_QUE_TRAVAM = ['nao_configurada_na_plataforma', 'desligada_no_escritorio', 'sem_permissao', 'limite_atingido'];
    var FALHA_AO_ENVIAR = 'Não foi possível enviar a pergunta agora. Tente novamente.';

    function intervaloPara(decorrido) {
        for (var i = 0; i < BACKOFF.length; i++) {
            if (decorrido < BACKOFF[i][0]) { return BACKOFF[i][1]; }
        }

        return BACKOFF[BACKOFF.length - 1][1];
    }

    function lerJson(r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
            return { ok: r.ok, status: r.status, dados: d || {} };
        });
    }

    function iniciar() {
        var drawer = document.querySelector('[data-ia-drawer]');
        var aba = drawer ? drawer.querySelector('[data-ia-aba-painel="perguntar"][data-ia-conversa-url]') : null;
        var rodape = drawer ? drawer.querySelector('[data-ia-conversa-rodape]') : null;
        if (!drawer || !aba || !rodape) { return; }

        var campo = rodape.querySelector('[data-ia-conversa-input]');
        var enviar = rodape.querySelector('[data-ia-conversa-enviar]');
        var aviso = rodape.querySelector('[data-ia-conversa-aviso]');
        var corpo = drawer.querySelector('.ia-drawer-corpo');
        var travado = rodape.getAttribute('data-ia-disponivel') !== '1';
        var timer = null;
        var carregando = false;
        var enviando = false;
        var inicio = Date.now();
        var limiteAvisado = false;

        function conversa() { return aba.querySelector('[data-ia-conversa]'); }
        function carregada() { var c = conversa(); return !!c && c.getAttribute('data-ia-carregado') === '1'; }
        function emAndamento() { var c = conversa(); return !!c && c.getAttribute('data-ia-em-andamento') === '1'; }
        function ativo() { return !document.hidden && !drawer.hidden; }

        function avisar(texto, erro) {
            if (!aviso || travado) { return; }
            aviso.textContent = texto || '';
            aviso.classList.toggle('is-erro', !!erro);
            aviso.hidden = !texto;
        }

        /* ── 4. Estado do campo, do botão e das sugestões ─────────────────── */
        function sincronizar() {
            var ocupado = travado || enviando || emAndamento();
            if (campo) { campo.disabled = travado; }
            if (enviar) { enviar.disabled = ocupado || !campo || campo.value.trim() === ''; }
            aba.querySelectorAll('[data-ia-sugestao]').forEach(function (b) { b.disabled = ocupado; });
        }

        function travar(texto) {
            if (aviso) {
                aviso.textContent = texto;
                aviso.classList.add('is-erro');
                aviso.hidden = false;
            }
            travado = true;
            if (campo) { campo.title = texto; }
            if (enviar) { enviar.title = texto; }
            sincronizar();
        }

        /* ── 1. Conversa ──────────────────────────────────────────────────── */
        function trocarConversa(html) {
            var molde = document.createElement('template');
            molde.innerHTML = html.trim();
            var nova = molde.content.querySelector('[data-ia-conversa]');
            var atual = conversa();
            if (nova && atual) { atual.replaceWith(nova); }
            if (corpo) { corpo.scrollTop = corpo.scrollHeight; }
        }

        function recarregar() {
            if (carregando) { return Promise.resolve(); }
            carregando = true;

            return fetch(aba.getAttribute('data-ia-conversa-url'), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) { throw new Error('Não foi possível carregar a conversa.'); }
                    return r.text();
                })
                .then(trocarConversa)
                .catch(function (falha) { avisar(falha && falha.message ? falha.message : 'Não foi possível carregar a conversa.', true); })
                .then(function () {
                    carregando = false;
                    sincronizar();
                    acompanhar();
                });
        }

        /* ── 3. Polling ───────────────────────────────────────────────────── */
        function pausar() {
            if (timer !== null) { clearTimeout(timer); }
            timer = null;
        }

        function perguntarDeNovo() {
            timer = null;
            if (!ativo()) { return; }
            recarregar();
        }

        function agendar() {
            if (timer !== null || carregando || !ativo() || !emAndamento()) { return; }
            if (Date.now() - inicio > LIMITE_MS) {
                if (!limiteAvisado) {
                    limiteAvisado = true;
                    avisar('A resposta está demorando mais que o normal. Recarregue a página mais tarde para ver o resultado.', true);
                }
                return;
            }
            timer = setTimeout(perguntarDeNovo, intervaloPara(Date.now() - inicio));
        }

        function acompanhar() {
            if (emAndamento()) {
                agendar();
                return;
            }
            inicio = Date.now();
            limiteAvisado = false;
        }

        // Abrir o drawer, mostrar a aba ou voltar à página: carrega na primeira vez, senão retoma.
        function retomar() {
            if (!ativo()) { pausar(); return; }
            if (!aba.hidden && !carregada()) { recarregar(); return; }
            inicio = Date.now();
            limiteAvisado = false;
            agendar();
        }

        var observador = new MutationObserver(retomar);
        observador.observe(drawer, { attributes: true, attributeFilter: ['hidden'] });
        observador.observe(aba, { attributes: true, attributeFilter: ['hidden'] });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { pausar(); return; }
            retomar();
        });

        /* ── 2. Enviar ────────────────────────────────────────────────────── */
        function aposEnviar(res) {
            enviando = false;
            var d = res.dados;
            if (!res.ok) {
                var texto = d.mensagem || FALHA_AO_ENVIAR;
                if (MOTIVOS_QUE_TRAVAM.indexOf(d.motivo) !== -1) { travar(texto); return; }
                avisar(texto, true);
                sincronizar();
                return;
            }
            // Com aviso, o servidor devolveu a pergunta que já estava em andamento: o texto digitado fica.
            if (d.aviso) { avisar(d.aviso, false); } else if (campo) { campo.value = ''; }
            inicio = Date.now();
            limiteAvisado = false;
            recarregar();
        }

        function enviarPergunta(texto) {
            texto = (texto || '').trim();
            if (!texto || travado || enviando || emAndamento()) { return; }
            enviando = true;
            avisar('');
            sincronizar();

            var dados = new FormData();
            dados.append('_token', drawer.getAttribute('data-ia-token') || '');
            dados.append('texto', texto);

            fetch(rodape.getAttribute('data-ia-perguntar-url'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: dados
            })
                .then(lerJson)
                .then(aposEnviar)
                .catch(function () {
                    enviando = false;
                    avisar(FALHA_AO_ENVIAR, true);
                    sincronizar();
                });
        }

        if (campo) {
            campo.addEventListener('input', sincronizar);
            campo.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.isComposing) { return; }
                e.preventDefault();
                enviarPergunta(campo.value);
            });
        }
        if (enviar) {
            enviar.addEventListener('click', function () { enviarPergunta(campo ? campo.value : ''); });
        }
        aba.addEventListener('click', function (e) {
            var sugestao = e.target.closest('[data-ia-sugestao]');
            if (!sugestao || sugestao.disabled) { return; }
            enviarPergunta(sugestao.getAttribute('data-ia-sugestao'));
        });

        sincronizar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
