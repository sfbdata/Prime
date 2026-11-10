/**
 * Aviso flutuante da tela do Ponto (E8, P80; desenho 16, L266 e `avisar` L468, spec docs/specs/ponto-desenho-0811.md).
 *
 * Faixa colorida no rodapé da tela, com `role="status"`: aparece com a subida curta do desenho (`bjpUp .2s`) e some
 * sozinha em 3.400 ms. Um por vez: um aviso novo troca o que está na tela e recomeça a contagem. Os tons são os fundos
 * do desenho: `ok` #1e7b45 (o padrão), `erro` #a3232b, `lembrete` #8a6200 e `gerando` #0a6fa0. Medidas e cores em
 * public/css/ponto-eletronico.css (`.pe-flutuante`).
 *
 * O elemento vem do template (`#ponto-aviso-flutuante`), já no documento antes de o texto chegar, para o leitor de tela
 * anunciar a troca. Sem ele (outra tela que carregue o arquivo, como a ficha do gestor, onde o "Baixar PDF" da folha
 * avisa por aqui), é criado no fim do `body`. O texto entra só por
 * `textContent`: a mensagem pode vir do servidor.
 *
 * Na carga, mostra o aviso que o servidor deixou para a tela (`data-aviso-na-carga` e o tom em
 * `data-aviso-na-carga-tom`, os flashes de tipo próprio que o `PontoController::index` lê antes do layout: "Enviado para
 * aprovação do gestor.", "Justificativa abonada.", "Justificativa recusada." e o abono em lote da Equipe).
 *
 * Com a tela cheia aberta (js/ponto-tela-cheia.js), o aviso espera ela fechar e aparece depois. Continua um por vez: o
 * que chega enquanto outro espera fica no lugar dele.
 *
 * Exposto em `window.PontoAvisoFlutuante`: `mostrar(texto, tom)` e `TEMPO_MS`.
 */
(function () {
    'use strict';

    if (window.PontoAvisoFlutuante) {
        return;
    }

    // `avisar` do desenho: some em 3.400 ms.
    const TEMPO_MS = 3400;
    const TONS = ['ok', 'erro', 'lembrete', 'gerando'];

    let prazo = null;
    let emEspera = null;

    function elemento() {
        let el = document.getElementById('ponto-aviso-flutuante');
        if (!el) {
            el = document.createElement('div');
            el.id = 'ponto-aviso-flutuante';
            el.className = 'pe-flutuante';
            el.setAttribute('role', 'status');
            el.hidden = true;
            document.body.appendChild(el);
        }

        return el;
    }

    function telaCheiaAberta() {
        const tela = window.PontoTelaCheia;

        return !!tela && typeof tela.fase === 'function' && typeof tela.quandoFechar === 'function' && tela.fase() !== '';
    }

    function mostrar(texto, tom) {
        if (telaCheiaAberta()) {
            const pedir = emEspera === null;
            emEspera = { texto: texto, tom: tom };
            if (pedir) {
                window.PontoTelaCheia.quandoFechar(function () {
                    const aviso = emEspera;
                    emEspera = null;
                    if (aviso) {
                        mostrar(aviso.texto, aviso.tom);
                    }
                });
            }
            return;
        }
        const el = elemento();
        const tomDoAviso = TONS.indexOf(tom) === -1 ? 'ok' : tom;
        clearTimeout(prazo);
        el.hidden = false;
        // A subida do desenho recomeça a cada aviso: sem a classe, um quadro, e de novo com ela.
        el.className = 'pe-flutuante';
        void el.offsetWidth;
        el.className = 'pe-flutuante pe-flutuante--' + tomDoAviso;
        el.textContent = String(texto || '');
        prazo = setTimeout(function () {
            el.hidden = true;
            el.textContent = '';
        }, TEMPO_MS);
    }

    function mostrarOQueVeioNaCarga() {
        const el = document.getElementById('ponto-aviso-flutuante');
        const texto = el ? el.getAttribute('data-aviso-na-carga') : '';
        if (texto) {
            const tom = el.getAttribute('data-aviso-na-carga-tom') || 'ok';
            el.removeAttribute('data-aviso-na-carga');
            el.removeAttribute('data-aviso-na-carga-tom');
            mostrar(texto, tom);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mostrarOQueVeioNaCarga);
    } else {
        mostrarOQueVeioNaCarga();
    }

    window.PontoAvisoFlutuante = Object.freeze({ TEMPO_MS, mostrar });
})();
