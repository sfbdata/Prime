/**
 * Tela cheia de validação do Ponto (E8, P79; desenho 16, L323 a L391 e `validar` L522 a L538, spec
 * docs/specs/ponto-desenho-0811.md). A marcação é do template (ponto/_tela_cheia.html.twig) e o CSS, de
 * public/css/ponto-eletronico.css (`.pe-tc`); aqui fica só a troca das fases, os tempos, o teclado e o foco.
 *
 * As fases:
 * - `val`: o anel de 24 barras acende em volta da hora, com "Validando {Tipo}", a data por extenso e as etapas;
 * - `ok`: a cortina verde, "Ponto registrado", a hora devolvida pelo servidor e "Concluir"; fecha sozinha em 10 s. Com
 *   o comprovante da batida (E15), o código e "Baixar comprovante" (desenho L365 e L368, `temCod`, `okCod`): o link
 *   baixa o arquivo, e o fechamento automático para, com a barra (`baixarAtual` e `limparAuto`, L829 e L832);
 * - `erro`: a cortina vermelha, "Ponto não registrado", o título e o detalhe do erro, "Tentar novamente" e "Fechar".
 *
 * Os tempos são os do desenho: a validação dura `VAL_T` (2.800 ms); o erro aparece em 55% dela (1.540 ms) e o sucesso no
 * fim. Quando a resposta do servidor demora mais do que isso, a validação espera a resposta: o resultado aparece quando
 * ela chega. A tela que chama decide o resultado; o que não tem resultado aqui (sem rede, sem confirmação, batida guardada
 * sem internet) fecha a validação com `fecharSeValidando()` e responde na caixa de hoje, abaixo do botão.
 *
 * Esc fecha no sucesso e no erro, nunca na validação. O foco vai à própria tela na validação e ao botão principal do
 * resultado ("Concluir" ou "Tentar novamente") e não sai dela com Tab (aria-modal). Ao fechar, vai ao destino que a tela
 * configurou (`configurar({ focoAoFechar })`; na /ponto, o título do cartão, que não aciona nada: um Enter logo depois
 * não vira outra batida) ou, sem ele, volta a quem o tinha. O movimento reduzido é do CSS do desenho.
 *
 * A última etapa da validação ("Registro selado", `[data-tc-selo]`) só marca depois de o servidor confirmar (`ok`): no
 * tempo dela (`data-tc-selo-em`) se a resposta já chegou, ou quando ela chegar. Com o servidor rápido, nada muda na tela;
 * no erro, ela não marca.
 *
 * O som e a vibração do resultado são os de `window.PontoSons` (js/ponto-sons.js), quando ele existe.
 *
 * Exposto em `window.PontoTelaCheia`:
 * - `validar({ rotulo, hora, dataExt })`: abre a validação;
 * - `ok({ rotulo, hora, dataExt, met, comprovante })`: o sucesso, no tempo do desenho. `met` troca a linha de baixo
 *   ("Registrado no sistema", a do template) por outro texto (a batida que já tinha entrado: "Esta batida já tinha
 *   sido registrada."). `comprovante` é o `{ codigo, url }` da resposta (`data.comprovante`); sem ele, ou com a url fora
 *   deste site, o código e o botão não aparecem;
 * - `erro({ titulo, detalhe, botoes, aoTentar })`: o erro, no tempo do desenho. `botoes` são os botões a mais da tela
 *   vermelha, entre "Tentar novamente" e "Fechar" (a E12 põe "Registrar como trabalho externo"):
 *   `[{ texto, icone, classe, aoClicar }]`; `icone` é um `bi-*`, `classe` vem por padrão `pe-tc-btn--externo` (o botão
 *   de borda branca do desenho) e `aoClicar(evento, segurar)` roda depois de a tela fechar, sem soltar quem espera:
 *   o botão que abre uma janela chama `segurar()` e recebe a função que solta a espera quando a janela fechar (sem
 *   `segurar()`, a espera sai logo depois do `aoClicar`). `aoTentar` é o "Tentar novamente";
 * - `quandoFechar(fn)`: roda `fn` quando a tela fechar (já, se ela está fechada). Para quem não pode aparecer por cima
 *   dela: o aviso flutuante, a janela do alerta de horário, a recarga do plano B;
 * - `segurar()`: o mesmo que o botão a mais recebe, para quem fecha a validação e abre uma janela no lugar dela (a
 *   batida externa que volta para a janela, a janela "Antes de registrar" da E11): chamado ANTES de fechar, o
 *   fechamento não solta quem espera, e a função devolvida solta quando a janela fechar;
 * - `configurar({ focoAoFechar })`: `focoAoFechar()` devolve o elemento que recebe o foco quando a tela fecha;
 * - `fecharSeValidando()`, `fechar()`, `fase()` (`val`, `ok`, `erro`, `tentar` nos 60 ms entre o "Tentar novamente" e
 *   o toque refeito, `janela` com a janela aberta por um botão a mais segurando a espera, ou vazio com a tela fechada)
 *   e `disponivel()`.
 */
(function () {
    'use strict';

    if (window.PontoTelaCheia) {
        return;
    }

    // `VAL_T` do desenho, e os dois instantes do resultado (L536 e L537).
    const VAL_T = 2800;
    const ERRO_EM_MS = Math.round(VAL_T * 0.55);
    const OK_EM_MS = VAL_T;
    // O sucesso fecha sozinho em 10 s, com a barra do rodapé (`bjpBarra 10s`).
    const FECHA_OK_MS = 10000;
    // "Tentar novamente": a tela fecha e o toque é refeito logo depois (`setTimeout(..., 60)` do desenho).
    const TENTAR_DEPOIS_MS = 60;
    // O "Baixar comprovante" é um link (como o "Baixar" da aba Comprovantes): sem `href`, ele não está na tela.
    const FOCAVEIS = 'button:not([disabled]), a[href]';

    let fase = '';
    let inicio = 0;
    let resultadoAgendado = false;
    let tempoDoResultado = null;
    let tempoDoFechamento = null;
    let focoAntes = null;
    let aoTentar = null;
    let focoAoFechar = null;
    let tempoDoSelo = null;
    let confirmado = false;
    const aoFechar = [];
    // Entre o "Tentar novamente" e o toque refeito (60 ms) a tela está fechada, mas quem espera continua esperando.
    let refazendo = false;
    // Janelas abertas por um botão a mais da tela vermelha (o "Registrar como trabalho externo") que seguram a espera
    // até fechar (`segurar`).
    let seguras = 0;

    function raiz() {
        return document.getElementById('ponto-tela-cheia');
    }

    function disponivel() {
        return !!raiz();
    }

    function escrever(seletor, texto) {
        raiz().querySelectorAll(seletor).forEach(function (no) {
            no.textContent = String(texto || '');
        });
    }

    function limparTempos() {
        clearTimeout(tempoDoResultado);
        clearTimeout(tempoDoFechamento);
        clearTimeout(tempoDoSelo);
        tempoDoResultado = null;
        tempoDoFechamento = null;
        tempoDoSelo = null;
    }

    // "Registro selado": `true` marca a etapa (a animação dela começa), `false` a deixa apagada para a validação nova.
    function selar(marcar) {
        raiz().querySelectorAll('[data-tc-selo]').forEach(function (etapa) {
            etapa.classList.toggle('pe-tc-etapa--selada', marcar);
        });
    }

    // O tempo da etapa no desenho, contado do início da validação (o template o põe em `data-tc-selo-em`).
    function seloEmMs() {
        const etapa = raiz().querySelector('[data-tc-selo]');
        const em = etapa ? Number(etapa.getAttribute('data-tc-selo-em')) : 0;

        return Number.isFinite(em) && em > 0 ? em : 0;
    }

    // Só a fase pedida aparece. Esconder e mostrar de novo recomeça as animações do desenho (as barras, a cortina).
    function mostrarFase(nome) {
        const el = raiz();
        el.querySelectorAll('[data-tc-fase]').forEach(function (parte) {
            parte.hidden = parte.getAttribute('data-tc-fase') !== nome;
        });
        el.setAttribute('data-fase', nome);
        el.hidden = false;
        fase = nome;
    }

    function focar(seletor) {
        const alvo = raiz().querySelector(seletor);
        if (alvo) {
            alvo.focus();
        }
    }

    function validar(dados) {
        const el = raiz();
        if (!el) {
            return false;
        }
        limparTempos();
        if (fase === '') {
            focoAntes = document.activeElement;
        }
        resultadoAgendado = false;
        confirmado = false;
        aoTentar = null;
        selar(false);
        // Uma validação nova recomeça as animações mesmo que outra estivesse na tela.
        el.hidden = true;
        void el.offsetWidth;
        mostrarFase('val');
        // O texto entra depois de a fase aparecer: a região `aria-live` já está na tela quando ele muda.
        escrever('[data-tc-tipo]', dados.rotulo);
        escrever('[data-tc-data]', dados.dataExt);
        escrever('[data-tc-hora-val]', dados.hora);
        inicio = performance.now();
        el.focus();

        return true;
    }

    // O resultado sai no instante do desenho, contado do início da validação, ou já, se a resposta chegou depois dele.
    // Sem validação aberta (quem chama pulou a validação), sai já.
    function agendar(emMs, mostrar) {
        clearTimeout(tempoDoResultado);
        resultadoAgendado = true;
        const espera = fase === 'val' ? inicio + emMs - performance.now() : 0;
        tempoDoResultado = setTimeout(mostrar, Math.max(0, espera));
    }

    function tocar(tipo) {
        if (window.PontoSons && typeof window.PontoSons.resultado === 'function') {
            window.PontoSons.resultado(tipo);
        }
    }

    function ok(dados) {
        if (!raiz()) {
            return false;
        }
        if (fase === '') {
            focoAntes = document.activeElement;
        }
        // O servidor confirmou: o selo marca no tempo dele, ou já, se ele passou.
        if (fase === 'val' && !confirmado) {
            confirmado = true;
            clearTimeout(tempoDoSelo);
            tempoDoSelo = setTimeout(function () { selar(true); }, Math.max(0, inicio + seloEmMs() - performance.now()));
        }
        agendar(OK_EM_MS, function () {
            mostrarFase('ok');
            escrever('[data-tc-tipo]', dados.rotulo);
            escrever('[data-tc-data]', dados.dataExt);
            escrever('[data-tc-hora-ok]', dados.hora);
            raiz().querySelectorAll('[data-tc-ok-met]').forEach(function (no) {
                no.textContent = dados.met ? String(dados.met) : String(no.getAttribute('data-padrao') || '');
            });
            mostrarComprovante(comprovanteValido(dados.comprovante));
            raiz().querySelectorAll('[data-tc-tempo]').forEach(function (barra) {
                barra.hidden = false;
            });
            tocar('ok');
            focar('[data-tc-concluir]');
            tempoDoFechamento = setTimeout(fechar, FECHA_OK_MS);
        });

        return true;
    }

    // O comprovante da batida (E15, `data.comprovante`): o código não vazio e a url um caminho deste site (nem outro
    // endereço, nem `javascript:`). Tab e quebra de linha também saem: o navegador os tira da url, e "/<tab>/outro.site"
    // viraria outro endereço. Senão, null: o sucesso fica como sem comprovante.
    function comprovanteValido(comprovante) {
        if (!comprovante || typeof comprovante.codigo !== 'string' || comprovante.codigo === '' || typeof comprovante.url !== 'string') {
            return null;
        }
        const url = comprovante.url;
        if (url.charAt(0) !== '/' || url.charAt(1) === '/' || url.charAt(1) === '\\' || /[\t\n\r]/.test(url)) {
            return null;
        }

        return { codigo: comprovante.codigo, url: url };
    }

    // O código (`okCod`, L365) e o "Baixar comprovante" (L368) só com o comprovante; o texto entra por `textContent`. A
    // linha de erro do download (a da aba, js/ponto-comprovantes.js) começa vazia a cada sucesso.
    function mostrarComprovante(comprovante) {
        const el = raiz();
        el.querySelectorAll('[data-tc-ok-cod]').forEach(function (no) {
            no.textContent = comprovante ? comprovante.codigo : '';
            no.hidden = !comprovante;
        });
        el.querySelectorAll('[data-tc-baixar]').forEach(function (link) {
            link.hidden = !comprovante;
            if (comprovante) {
                link.setAttribute('href', comprovante.url);
                link.setAttribute('aria-label', 'Baixar comprovante ' + comprovante.codigo);
            } else {
                link.removeAttribute('href');
                link.removeAttribute('aria-label');
            }
        });
        el.querySelectorAll('[data-tc-fase="ok"] [data-baixar-falha]').forEach(function (falha) {
            falha.textContent = '';
            falha.hidden = true;
        });
    }

    // "Baixar comprovante" (`baixarAtual` e `limparAuto`, L829 e L832): o fechamento automático para e a barra some; a
    // tela fica até o "Concluir" (ou o Esc).
    function pararFechamentoAutomatico() {
        clearTimeout(tempoDoFechamento);
        tempoDoFechamento = null;
        raiz().querySelectorAll('[data-tc-tempo]').forEach(function (barra) {
            barra.hidden = true;
        });
    }

    function botaoAMais(botao) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'pe-tc-btn ' + (botao.classe || 'pe-tc-btn--externo');
        el.setAttribute('data-tc-extra', '');
        if (botao.icone) {
            const icone = document.createElement('i');
            icone.className = 'bi ' + botao.icone;
            icone.setAttribute('aria-hidden', 'true');
            el.appendChild(icone);
        }
        el.appendChild(document.createTextNode(String(botao.texto || '')));
        // Fecha sem soltar quem espera (o alerta de horário, o aviso flutuante): como no "Tentar novamente", nada abre por
        // cima do que o botão abriu. Quem não segura (`segurar`) solta a espera logo depois do `aoClicar`.
        el.addEventListener('click', function (evento) {
            fecharTela(false);
            try {
                if (typeof botao.aoClicar === 'function') {
                    botao.aoClicar(evento, segurar);
                }
            } finally {
                if (livre()) {
                    soltarQuemEspera();
                }
            }
        });

        return el;
    }

    function erro(dados) {
        if (!raiz()) {
            return false;
        }
        if (fase === '') {
            focoAntes = document.activeElement;
        }
        agendar(ERRO_EM_MS, function () {
            const el = raiz();
            el.querySelectorAll('[data-tc-extra]').forEach(function (velho) {
                velho.remove();
            });
            const fecharBotao = el.querySelector('[data-tc-fase="erro"] [data-tc-fechar]');
            (Array.isArray(dados.botoes) ? dados.botoes : []).forEach(function (botao) {
                fecharBotao.parentNode.insertBefore(botaoAMais(botao), fecharBotao);
            });
            aoTentar = typeof dados.aoTentar === 'function' ? dados.aoTentar : null;
            mostrarFase('erro');
            escrever('[data-tc-erro-titulo]', dados.titulo);
            escrever('[data-tc-erro-detalhe]', dados.detalhe);
            // Detalhe vazio (recusa do servidor: a mensagem dele é o título) não deixa o espaço de uma linha vazia.
            el.querySelectorAll('[data-tc-erro-detalhe]').forEach(function (no) {
                no.hidden = !dados.detalhe;
            });
            tocar('erro');
            focar('[data-tc-tentar]');
        });

        return true;
    }

    // Quem esperava a tela fechar (`quandoFechar`), na ordem em que pediu. Um que falha não segura os outros.
    function soltarQuemEspera() {
        aoFechar.splice(0).forEach(function (fn) {
            try {
                fn();
            } catch (erro) {
                console.error('Falha depois de fechar a tela cheia:', erro);
            }
        });
    }

    function fechar() {
        fecharTela(true);
    }

    // Fechada de verdade: sem a tela, sem o refazer do "Tentar novamente" e sem janela segurando a espera.
    function livre() {
        return fase === '' && !refazendo && seguras === 0;
    }

    // Para o `aoClicar` do botão a mais que abre uma janela: a espera continua até a função devolvida ser chamada (uma
    // vez só; chamá-la de novo não faz nada). A conferência fica para depois do código em curso: o toque refeito na
    // janela ("Registrar") abre a validação antes, e então a espera segue até ela fechar.
    function segurar() {
        seguras++;
        let solto = false;

        return function () {
            if (solto) {
                return;
            }
            solto = true;
            seguras--;
            setTimeout(function () {
                if (livre()) {
                    soltarQuemEspera();
                }
            }, 0);
        };
    }

    // `soltar` false: o "Tentar novamente" fecha para refazer o toque, e quem espera (o alerta de horário, o aviso
    // flutuante, a recarga) continua esperando a tela fechar de verdade (`tentarDeNovo`).
    function fecharTela(soltar) {
        const el = raiz();
        limparTempos();
        resultadoAgendado = false;
        aoTentar = null;
        if (!el || fase === '') {
            fase = '';
            if (soltar && livre()) {
                soltarQuemEspera();
            }
            return;
        }
        el.hidden = true;
        el.removeAttribute('data-fase');
        el.querySelectorAll('[data-tc-fase]').forEach(function (parte) {
            parte.hidden = true;
        });
        fase = '';
        // O destino configurado (na /ponto, o título do cartão, que não aciona nada); sem ele, quem tinha o foco.
        const destino = focoAoFechar ? focoAoFechar() : focoAntes;
        if (destino && document.contains(destino) && typeof destino.focus === 'function') {
            destino.focus();
        }
        focoAntes = null;
        if (soltar && livre()) {
            soltarQuemEspera();
        }
    }

    function quandoFechar(fn) {
        if (typeof fn !== 'function') {
            return;
        }
        if (livre()) {
            fn();
            return;
        }
        aoFechar.push(fn);
    }

    function configurar(opcoes) {
        if (opcoes && typeof opcoes.focoAoFechar === 'function') {
            focoAoFechar = opcoes.focoAoFechar;
        }
    }

    // A validação aberta sem resultado para esta tela (sem rede, sem confirmação, guardada): fecha, e a resposta fica
    // na caixa de hoje.
    function fecharSeValidando() {
        if (fase === 'val' && !resultadoAgendado) {
            fechar();
        }
    }

    // "Tentar novamente": a tela fecha SEM soltar quem espera e o toque é refeito logo depois. Se ele abriu a validação
    // nova, a espera segue até ela fechar (é o fechamento dela que solta); se não abriu (o tipo já batido, o botão
    // ocupado, a falta do tipo), a tela fechou de verdade e quem espera sai agora.
    function tentarDeNovo() {
        const refazer = aoTentar;
        fecharTela(false);
        refazendo = true;
        setTimeout(function () {
            refazendo = false;
            try {
                if (refazer) {
                    refazer();
                }
            } finally {
                if (livre()) {
                    soltarQuemEspera();
                }
            }
        }, TENTAR_DEPOIS_MS);
    }

    // O foco não sai da tela com Tab (aria-modal). Na validação não há botão: fica na própria tela.
    function prenderFoco(evento) {
        const el = raiz();
        const focaveis = Array.from(el.querySelectorAll(FOCAVEIS)).filter(function (no) {
            return !no.closest('[hidden]');
        });
        if (focaveis.length === 0) {
            evento.preventDefault();
            el.focus();
            return;
        }
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (!el.contains(document.activeElement)) {
            evento.preventDefault();
            primeiro.focus();
        } else if (evento.shiftKey && document.activeElement === primeiro) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primeiro.focus();
        }
    }

    document.addEventListener('keydown', function (evento) {
        if (fase === '') {
            return;
        }
        if (evento.key === 'Escape' && (fase === 'ok' || fase === 'erro')) {
            fechar();
        } else if (evento.key === 'Tab') {
            prenderFoco(evento);
        }
    });

    document.addEventListener('click', function (evento) {
        const el = raiz();
        // O download é do próprio link (e, com js/ponto-comprovantes.js, do fetch dele); aqui só para o fechamento.
        const baixar = evento.target.closest ? evento.target.closest('[data-tc-baixar]') : null;
        if (el && baixar && el.contains(baixar)) {
            pararFechamentoAutomatico();
            return;
        }
        const alvo = evento.target.closest ? evento.target.closest('[data-tc-concluir], [data-tc-fechar], [data-tc-tentar]') : null;
        if (!el || !alvo || !el.contains(alvo)) {
            return;
        }
        if (alvo.hasAttribute('data-tc-tentar')) {
            tentarDeNovo();
            return;
        }
        fechar();
    });

    window.PontoTelaCheia = Object.freeze({
        VAL_T,
        ERRO_EM_MS,
        FECHA_OK_MS,
        disponivel,
        validar,
        ok,
        erro,
        fechar,
        fecharSeValidando,
        quandoFechar,
        segurar,
        configurar,
        // Nos 60 ms do "Tentar novamente", `tentar`; com a janela de um botão a mais aberta, `janela`: quem pergunta antes
        // de aparecer (o aviso flutuante, o alerta de horário) espera, como com a tela aberta.
        fase: function () {
            if (fase !== '') {
                return fase;
            }
            if (refazendo) {
                return 'tentar';
            }

            return seguras > 0 ? 'janela' : '';
        },
    });
})();
