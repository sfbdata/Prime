/*
 * Som do alerta de ponto (pedido do Samuel, 05/11/2026).
 *
 * O alerta de ponto (templates/ponto/_modal_alerta_ponto.html.twig) aparece em toda página e consulta
 * /ponto/alerta-horario a cada 60 s. Quando ele abre a hora de voltar do intervalo ("retorno") ou a hora
 * de ir embora ("saida"), chama window.BJSomDoAlertaDePonto.tocar(tipo, modal). Só esses dois tipos tocam.
 *
 * - "Sons de alerta" do Meu Perfil desligado: o modal sai com data-perfil-sons="0" e nada toca.
 * - O navegador só deixa tocar depois da primeira interação com a página (clique, tecla ou toque). Antes
 *   disso, ou com o áudio bloqueado, fica só o modal, sem erro no console.
 * - Um toque por tipo por dia (localStorage, mesma chave do aviso com o sufixo "_som"): a consulta de 60 s
 *   não repete o som enquanto o modal continua aberto.
 * - Som sintetizado (AudioContext), como em lembretes.js: nenhum arquivo de áudio.
 */
(function () {
    'use strict';

    // Volta do intervalo: duas notas subindo. Fim da jornada: três notas descendo.
    var TIPOS_COM_SOM = { retorno: [660, 880], saida: [880, 740, 587] };
    var PREFIXO = 'alerta_ponto_';

    var ctx = null;
    var liberado = false;

    function liberarAudio() {
        liberado = true;
        try {
            ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') { ctx.resume().catch(function () {}); }
        } catch (e) { ctx = null; }
    }
    addEventListener('pointerdown', liberarAudio, { once: true, capture: true });
    addEventListener('keydown', liberarAudio, { once: true, capture: true });
    addEventListener('touchend', liberarAudio, { once: true, capture: true });

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

    function tocar(tipo, modal) {
        var frequencias = TIPOS_COM_SOM[tipo];
        if (!frequencias) { return; }
        var perfilSons = !!modal && modal.getAttribute('data-perfil-sons') !== '0';
        if (!perfilSons || !liberado || !ctx || jaTocouHoje(tipo)) { return; }
        try {
            if (ctx.state === 'suspended') { ctx.resume().catch(function () {}); }
            notas(frequencias);
            marcarQueTocou(tipo);
        } catch (e) { /* áudio bloqueado: fica só o modal */ }
    }

    window.BJSomDoAlertaDePonto = { tocar: tocar };
}());
