/*
 * Som do alerta de ponto (pedido do Samuel, 05/11/2026).
 *
 * O alerta de ponto (templates/ponto/_modal_alerta_ponto.html.twig) aparece em toda página e consulta
 * /ponto/alerta-horario a cada 60 s. Quando ele abre a hora de voltar do intervalo ("retorno") ou a hora
 * de ir embora ("saida"), chama window.BJSomDoAlertaDePonto.tocar(tipo, modal). Só esses dois tipos tocam.
 *
 * - "Sons de alerta" do Meu Perfil desligado: o modal sai com data-perfil-sons="0" e nada toca.
 * - O navegador só deixa tocar depois de uma interação com a PÁGINA ATUAL (clique, tecla ou toque); cada
 *   recarga zera isso, e a batida do intervalo recarrega a tela do ponto. Por isso:
 *   1. se o navegador já permite som sem interação (getAutoplayPolicy, onde existe), o áudio é ligado ao
 *      carregar a página;
 *   2. senão, o alerta que abriu antes da primeira interação fica PENDENTE e toca nessa interação, se o
 *      aviso ainda estiver aberto.
 *   Bloqueado de vez, fica só o modal (e o título piscando e a notificação do navegador), sem erro no console.
 * - Um toque por tipo por dia (localStorage, mesma chave do aviso com o sufixo "_som"), marcado só depois
 *   que o som de fato saiu: a consulta de 60 s não repete o som enquanto o modal continua aberto.
 * - Som sintetizado (AudioContext), como em lembretes.js: nenhum arquivo de áudio.
 */
(function () {
    'use strict';

    // Volta do intervalo: duas notas subindo. Fim da jornada: três notas descendo.
    var TIPOS_COM_SOM = { retorno: [660, 880], saida: [880, 740, 587] };
    var PREFIXO = 'alerta_ponto_';

    var ctx = null;
    var liberado = false;
    var pendente = null; // { tipo, modal } do alerta que abriu antes da primeira interação

    function semErro() {}

    // resume() do webkitAudioContext antigo pode não devolver promessa.
    function retomar(depois) {
        var promessa = null;
        try { promessa = ctx.resume(); } catch (e) { promessa = null; }
        if (promessa && typeof promessa.then === 'function') {
            promessa.then(depois, semErro);

            return;
        }
        depois();
    }

    function criarContexto() {
        if (ctx) { return true; }
        try {
            var Classe = window.AudioContext || window.webkitAudioContext;
            if (!Classe) { return false; }
            ctx = new Classe();

            return true;
        } catch (e) {
            ctx = null;

            return false;
        }
    }

    function liberarAudio() {
        liberado = true;
        if (!criarContexto()) { return; }
        if (pendente) {
            var agora = pendente;
            pendente = null;
            // O aviso pode ter sido fechado nesse meio-tempo: aí não toca (e o dia não fica marcado).
            if (avisoAberto(agora.modal)) { tocar(agora.tipo, agora.modal); }
        }
    }
    addEventListener('pointerdown', liberarAudio, { once: true, capture: true });
    addEventListener('keydown', liberarAudio, { once: true, capture: true });
    addEventListener('touchend', liberarAudio, { once: true, capture: true });

    // Onde o navegador conta que o som já é permitido sem interação, liga logo (sem aviso no console).
    try {
        if (navigator.getAutoplayPolicy && navigator.getAutoplayPolicy('audiocontext') === 'allowed') {
            liberarAudio();
        }
    } catch (e) { /* política indisponível: espera a interação */ }

    function chaveDeHoje(tipo) {
        var d = new Date();
        return PREFIXO + d.getFullYear()
            + '-' + String(d.getMonth() + 1).padStart(2, '0')
            + '-' + String(d.getDate()).padStart(2, '0')
            + '_' + tipo + '_som';
    }

    function jaTocouHoje(tipo) {
        try { return localStorage.getItem(chaveDeHoje(tipo)) === '1'; } catch (e) { return false; }
    }

    function marcarQueTocou(tipo) {
        try { localStorage.setItem(chaveDeHoje(tipo), '1'); } catch (e) { /* sem armazenamento */ }
    }

    function notas(frequencias) {
        var t0 = ctx.currentTime;
        frequencias.forEach(function (f, i) {
            var o = ctx.createOscillator();
            var g = ctx.createGain();
            var inicio = t0 + i * 0.22;
            o.type = 'sine';
            o.frequency.value = f;
            g.gain.setValueAtTime(0.0001, inicio);
            g.gain.exponentialRampToValueAtTime(0.25, inicio + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, inicio + 0.38);
            o.connect(g);
            g.connect(ctx.destination);
            o.start(inicio);
            o.stop(inicio + 0.4);
        });
    }

    function avisoAberto(modal) {
        return !!modal && modal.classList.contains('show');
    }

    function tocar(tipo, modal) {
        var frequencias = TIPOS_COM_SOM[tipo];
        var perfilSons = !!modal && modal.getAttribute('data-perfil-sons') !== '0';
        if (!frequencias || !perfilSons || jaTocouHoje(tipo)) { return; }
        if (!liberado || !ctx) {
            // Ainda sem interação nesta página: toca quando ela vier, se o aviso continuar aberto.
            pendente = { tipo: tipo, modal: modal };

            return;
        }
        retomar(function () {
            // Só marca o dia quando o som saiu de fato (contexto rodando).
            if (ctx.state !== 'running' || jaTocouHoje(tipo)) { return; }
            try {
                notas(frequencias);
                marcarQueTocou(tipo);
            } catch (e) { /* áudio bloqueado: fica só o modal */ }
        });
    }

    window.BJSomDoAlertaDePonto = { tocar: tocar };
}());
