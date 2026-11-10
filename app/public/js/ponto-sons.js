/**
 * Sons e vibração do Ponto (E8, P81 e P82; desenho 16, `audio`, `tom`, `somOk`, `somErro` e `vibra`, L481 a L486, e o
 * botão de som do rodapé do cartão, L136, spec docs/specs/ponto-desenho-0811.md).
 *
 * - O som é gerado no navegador (`AudioContext`), sem arquivo, com os números do desenho: o "plim" do registro e o
 *   alerta de onda quadrada da falha.
 * - Toca só com a função `ponto-sons` valendo (Global E Escritório; a tela chama `configurar`) e sem o mudo.
 * - O contexto de áudio nasce (ou volta) em `liberar()`, que a tela chama no toque: é o gesto da pessoa que o navegador
 *   exige. Este arquivo tem o PRÓPRIO contexto e não mexe no do alerta de horário (js/ponto-alerta-som.js,
 *   `BJSomDoAlertaDePonto`), que continua liberado pela primeira interação com a página: nada aqui para ou cancela
 *   o clique, a tecla ou o toque.
 * - A vibração (40 ms no sucesso, três pulsos na falha) não depende da chave nem do mudo, como no desenho.
 * - O mudo é do aparelho, em `localStorage` (`bj-ponto-mudo`, o valor do desenho), sempre dentro de try/catch: sem
 *   armazenamento, o som fica ligado.
 * - O botão de som (`#btn-som-ponto`) só existe com a função ligada; o clique troca o mudo, o ícone e a dica.
 *
 * Exposto em `window.PontoSons`.
 */
(function () {
    'use strict';

    if (window.PontoSons) {
        return;
    }

    const CHAVE_MUDO = 'bj-ponto-mudo';
    const DICA_SILENCIAR = 'Silenciar sons';
    const DICA_ATIVAR = 'Ativar sons';

    // Quanto um som espera o contexto voltar depois do toque. Passou disso, o som não sai: ele tocaria fora de hora.
    const ESPERA_DA_RETOMADA_MS = 1500;

    let ligados = false;
    let ctx = null;
    let retomada = null;

    function mudo() {
        try {
            return JSON.parse(localStorage.getItem(CHAVE_MUDO)) === true;
        } catch (erro) {
            return false;
        }
    }

    function definirMudo(valor) {
        try {
            localStorage.setItem(CHAVE_MUDO, JSON.stringify(!!valor));
        } catch (erro) {
            /* Sem armazenamento (modo privado, cota): o mudo não fica guardado. */
        }
    }

    function podeTocar() {
        return ligados && !mudo();
    }

    // `audio()` do desenho: cria o contexto ou o retoma. Chamada no toque (o gesto da pessoa).
    function liberar() {
        if (!ligados) {
            return;
        }
        try {
            const Classe = window.AudioContext || window.webkitAudioContext;
            if (!Classe) {
                return;
            }
            if (!ctx) {
                ctx = new Classe();
            }
            if (ctx.state === 'suspended') {
                const pedido = ctx.resume();
                retomada = pedido && typeof pedido.then === 'function' ? pedido : null;
                if (retomada) {
                    retomada.catch(function () {});
                }
            }
        } catch (erro) {
            /* Navegador sem áudio: fica só a tela. */
        }
    }

    // `tom(f, ini, dur, vol, tipo)` do desenho: ataque de .012 s e queda exponencial.
    function tom(frequencia, inicio, duracao, volume, tipo) {
        if (!ctx) {
            return;
        }
        try {
            const t = ctx.currentTime + inicio;
            const oscilador = ctx.createOscillator();
            const ganho = ctx.createGain();
            oscilador.type = tipo || 'sine';
            oscilador.frequency.value = frequencia;
            ganho.gain.setValueAtTime(0.0001, t);
            ganho.gain.exponentialRampToValueAtTime(volume, t + 0.012);
            ganho.gain.exponentialRampToValueAtTime(0.0001, t + duracao);
            oscilador.connect(ganho);
            ganho.connect(ctx.destination);
            oscilador.start(t);
            oscilador.stop(t + duracao + 0.05);
        } catch (erro) {
            /* Falha do áudio não interrompe a tela. */
        }
    }

    // Só toca com o contexto rodando. Um contexto parado (criado fora de um gesto, como no lembrete com a página recém
    // aberta) guardaria o som e o tocaria na próxima interação, fora de hora: aí o som não sai. A retomada pedida no
    // toque é esperada por pouco tempo.
    function quandoPronto(tocar) {
        if (!ctx) {
            return;
        }
        if (ctx.state === 'running') {
            tocar();
            return;
        }
        if (!retomada) {
            return;
        }
        const pedidoEm = Date.now();
        retomada.then(function () {
            if (ctx.state === 'running' && Date.now() - pedidoEm <= ESPERA_DA_RETOMADA_MS) {
                tocar();
            }
        }, function () {});
    }

    // `somOk` do desenho (o "plim").
    function ok() {
        if (!podeTocar()) {
            return;
        }
        quandoPronto(function () {
            tom(1568, 0, 0.55, 0.26);
            tom(3136, 0, 0.25, 0.06);
            tom(2093, 0.1, 0.85, 0.3);
            tom(4186, 0.1, 0.35, 0.07);
        });
    }

    // `somErro` do desenho (onda quadrada).
    function erro() {
        if (!podeTocar()) {
            return;
        }
        quandoPronto(function () {
            tom(233, 0, 0.2, 0.3, 'square');
            tom(185, 0.24, 0.36, 0.3, 'square');
        });
    }

    // `vibra` do desenho.
    function vibrar(padrao) {
        try {
            if (navigator.vibrate) {
                navigator.vibrate(padrao);
            }
        } catch (falha) {
            /* Aparelho sem vibração. */
        }
    }

    // O resultado da batida: o som (se valer) e a vibração. Sucesso 40 ms; falha [80, 60, 80].
    function resultado(tipo) {
        if (tipo === 'ok') {
            ok();
            vibrar(40);
            return;
        }
        erro();
        vibrar([80, 60, 80]);
    }

    // O botão do rodapé (L136): o alto-falante com o som ligado, o riscado com o mudo; "Silenciar sons" / "Ativar sons".
    function desenharBotao() {
        const botao = document.getElementById('btn-som-ponto');
        if (!botao) {
            return;
        }
        const estaMudo = mudo();
        const dica = estaMudo ? DICA_ATIVAR : DICA_SILENCIAR;
        botao.setAttribute('aria-label', dica);
        botao.setAttribute('title', dica);
        // Os ícones são SVG: o `hidden` vai pelo atributo (o elemento SVG não tem a propriedade).
        const ligado = botao.querySelector('[data-som-ligado]');
        const desligado = botao.querySelector('[data-som-mudo]');
        if (ligado) {
            ligado.toggleAttribute('hidden', estaMudo);
        }
        if (desligado) {
            desligado.toggleAttribute('hidden', !estaMudo);
        }
    }

    document.addEventListener('click', function (evento) {
        const botao = evento.target.closest && evento.target.closest('#btn-som-ponto');
        if (!botao) {
            return;
        }
        definirMudo(!mudo());
        desenharBotao();
    });

    // A tela diz se a função vale (Global E Escritório, `PontoController::index`).
    function configurar(opcoes) {
        ligados = !!(opcoes && opcoes.ligados);
        desenharBotao();
    }

    window.PontoSons = Object.freeze({ CHAVE_MUDO, configurar, liberar, ok, erro, vibrar, resultado, mudo });
})();
