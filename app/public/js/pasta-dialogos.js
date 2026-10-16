/**
 * Diálogos da tela da pasta (lote L6). Fica num arquivo próprio para não tocar o pasta-show.js.
 *
 * 1. Contador de caracteres (PMC-039): `[data-ps-nm-contador="<id do campo>"]` mostra "N/limite"
 *    com o `maxlength` do próprio campo.
 * 2. Responsáveis da Nova meta (PMC-041; desenho dc L.168-186): chips sobre o campo e a lista
 *    filtrável. O `<select multiple>` (`#tarefaResponsavel`) é a FONTE: aqui só se marcam e
 *    desmarcam as `<option>` dele, e o envio do formulário continua o de sempre.
 * 3. Calendário do prazo (PMC-040; dc L.81-112, `nm.cal`): o botão "dd/mm/aaaa" abre mês, dias,
 *    Hoje, Limpar e a legenda. O `<input type="date">` (`#tarefaPrazo`) continua sendo o campo
 *    enviado: escolher um dia grava o ISO nele e dispara `change` (o texto do dia e os atalhos do
 *    pasta-metas.js ouvem esse evento).
 * 4. `confirmarPs`/`avisarPs` (PAS-034; dc L.280-288 `cf`, L.597 `edAviso`): a janela de
 *    confirmação do desenho (título, trecho, Cancelar e o botão vermelho; o foco nasce no
 *    Cancelar e Enter nele cancela, Enter fora dele confirma, Esc cancela) e o aviso no rodapé da
 *    tela, no lugar do `confirm`/`alert` do navegador. O anúncio do aviso sai por regiões vivas
 *    criadas na carga (PAS-034c). Sem a marcação na página (outra tela), caem no
 *    `confirm`/`alert` — nunca somem com a pergunta.
 * 5. Variante azul e `confirmarUIPs` (E7 da fidelidade, dc 02 L.6185-6187 `confirmarUI`/`cfVals`): o
 *    desenho pinta o botão de AZUL (#0a6fa0, ✓ "Confirmar") quando a ação não é perigo e de vermelho
 *    (#b3262e, ⚠) quando é; a primeira linha da mensagem vira o título. `confirmarUIPs(msg, perigo)`
 *    reproduz essa regra para quem tinha um `confirm(msg)`.
 * 6. Envio de formulário com confirmação (E7): `<form data-ps-cf="Título\nTexto" [data-ps-cf-perigo]>`
 *    ou `psConfirmarEnvio(form, fn)` (pergunta calculada na hora). Um ouvinte de `submit` em CAPTURA
 *    segura o envio ANTES de qualquer outro ouvinte do formulário (XHR, `onsubmit`); só no "sim" o
 *    formulário é reenviado com `requestSubmit` — e aí os ouvintes de sempre rodam, uma vez.
 *
 * Nada aqui escreve HTML com dado de usuário: os nomes vêm do Twig (escapados) e o JS só usa
 * textContent, atributos e cópia de nós já desenhados.
 */
(function () {
    'use strict';

    var modalMeta = document.getElementById('modalCriarTarefa');

    /* ── 1. Contador ─────────────────────────────────────────────────────── */
    function contadores() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ps-nm-contador]'), function (alvo) {
            var campo = document.getElementById(alvo.getAttribute('data-ps-nm-contador'));
            if (!campo) { return; }
            var limite = campo.getAttribute('maxlength') || '';

            function atualizar() {
                alvo.textContent = String(campo.value.length) + (limite ? '/' + limite : '');
            }

            campo.addEventListener('input', atualizar);
            // Outros scripts preenchem o campo sem disparar `input` (Push, IA, observação) e
            // limpam ao fechar: o modal recontar ao abrir e ao fechar cobre os dois.
            if (modalMeta && modalMeta.contains(campo)) {
                modalMeta.addEventListener('show.bs.modal', atualizar);
                modalMeta.addEventListener('hidden.bs.modal', atualizar);
            }
            atualizar();
        });
    }

    /* ── 2. Responsáveis da Nova meta ────────────────────────────────────── */
    function semAcento(t) {
        return String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    function seletorDeResponsaveis() {
        var raiz = document.querySelector('[data-ps-nm-resp]');
        var select = document.getElementById('tarefaResponsavel');
        if (!raiz || !select) { return; }
        var campo = raiz.querySelector('[data-ps-nm-resp-campo]');
        var chips = raiz.querySelector('[data-ps-nm-resp-chips]');
        var vazio = raiz.querySelector('[data-ps-nm-resp-vazio]');
        var lista = raiz.querySelector('[data-ps-nm-resp-lista]');
        var busca = raiz.querySelector('[data-ps-nm-resp-busca]');
        var nada = raiz.querySelector('[data-ps-nm-resp-nada]');
        var concluir = raiz.querySelector('[data-ps-nm-resp-concluir]');
        var opcoes = Array.prototype.slice.call(raiz.querySelectorAll('[data-ps-nm-resp-opcao]'));

        function optionDe(id) {
            return select.querySelector('option[value="' + String(id).replace(/[^0-9]/g, '') + '"]');
        }

        function chipDe(botao, option) {
            var chip = document.createElement('span');
            chip.className = 'ps-nm-resp-chip';
            var av = botao.querySelector('.resp-avatar');
            if (av) {
                var copia = av.cloneNode(true);
                copia.style.width = '22px';
                copia.style.height = '22px';
                copia.style.fontSize = '9px';
                chip.appendChild(copia);
            }
            var nome = document.createElement('span');
            nome.className = 'ps-nm-resp-chip-nome';
            nome.textContent = botao.getAttribute('data-nome') || option.textContent;
            chip.appendChild(nome);
            var tirar = document.createElement('button');
            tirar.type = 'button';
            tirar.className = 'ps-nm-resp-chip-tirar';
            tirar.title = 'Remover';
            tirar.setAttribute('aria-label', 'Remover ' + nome.textContent);
            tirar.setAttribute('data-ps-nm-resp-tirar', option.value);
            var x = document.createElement('i');
            x.className = 'bi bi-x';
            x.setAttribute('aria-hidden', 'true');
            tirar.appendChild(x);
            chip.appendChild(tirar);

            return chip;
        }

        /** Redesenha chips e marcas a partir do <select> (a fonte). */
        function pintar() {
            chips.textContent = '';
            var algum = false;
            opcoes.forEach(function (botao) {
                var option = optionDe(botao.getAttribute('data-ps-nm-resp-opcao'));
                var on = !!(option && option.selected);
                botao.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) {
                    chips.appendChild(chipDe(botao, option));
                    algum = true;
                }
            });
            vazio.hidden = algum;
        }

        function alternar(id) {
            var option = optionDe(id);
            if (!option) { return; }
            option.selected = !option.selected;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            pintar();
        }

        function filtrar() {
            var q = semAcento(busca.value);
            var visiveis = 0;
            opcoes.forEach(function (botao) {
                var ok = q === '' || semAcento(botao.getAttribute('data-nome')).indexOf(q) !== -1;
                botao.hidden = !ok;
                if (ok) { visiveis++; }
            });
            nada.hidden = visiveis > 0;
        }

        function aberta() { return !lista.hidden; }

        function abrir() {
            lista.hidden = false;
            campo.setAttribute('aria-expanded', 'true');
            busca.value = '';
            filtrar();
            setTimeout(function () { busca.focus(); }, 30);
        }

        function fechar(devolverFoco) {
            if (!aberta()) { return; }
            lista.hidden = true;
            campo.setAttribute('aria-expanded', 'false');
            if (devolverFoco) { campo.focus(); }
        }

        campo.addEventListener('click', function (e) {
            var tirar = e.target.closest('[data-ps-nm-resp-tirar]');
            if (tirar) {
                e.stopPropagation();
                alternar(tirar.getAttribute('data-ps-nm-resp-tirar'));

                return;
            }
            if (aberta()) { fechar(false); } else { abrir(); }
        });
        campo.addEventListener('keydown', function (e) {
            if (e.target !== campo) { return; }
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                abrir();
            }
        });

        lista.addEventListener('click', function (e) {
            var botao = e.target.closest('[data-ps-nm-resp-opcao]');
            if (botao) { alternar(botao.getAttribute('data-ps-nm-resp-opcao')); }
        });
        concluir.addEventListener('click', function () { fechar(true); });
        busca.addEventListener('input', filtrar);

        // Esc fecha a LISTA, não o modal (desenho: `teclaBusca` para a propagação); Enter na
        // busca marca o primeiro nome que sobrou e limpa o filtro.
        lista.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                fechar(true);
            } else if (e.key === 'Enter' && e.target === busca) {
                e.preventDefault();
                var primeiro = opcoes.filter(function (b) { return !b.hidden; })[0];
                if (primeiro) {
                    alternar(primeiro.getAttribute('data-ps-nm-resp-opcao'));
                    busca.value = '';
                    filtrar();
                }
            }
        });

        // Clique fora do bloco fecha a lista.
        document.addEventListener('click', function (e) {
            if (aberta() && !raiz.contains(e.target)) { fechar(false); }
        }, true);

        select.addEventListener('change', pintar);
        if (modalMeta) {
            modalMeta.addEventListener('show.bs.modal', pintar);
            modalMeta.addEventListener('hidden.bs.modal', function () { fechar(false); pintar(); });
        }
        pintar();
    }

    /* ── 3. Calendário do prazo ───────────────────────────────────────────── */
    var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto',
        'setembro', 'outubro', 'novembro', 'dezembro'];

    function isoDe(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function dataDe(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));

        return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
    }

    function calendarioDoPrazo() {
        var input = document.getElementById('tarefaPrazo');
        var campo = document.querySelector('[data-ps-nm-cal-campo]');
        var cal = document.querySelector('[data-ps-nm-cal]');
        if (!input || !campo || !cal) { return; }
        var txt = campo.querySelector('[data-ps-nm-cal-txt]');
        var mesTxt = cal.querySelector('[data-ps-nm-cal-mes]');
        var dias = cal.querySelector('[data-ps-nm-cal-dias]');
        var base = null; // 1º dia do mês à vista

        function hoje() {
            var d = new Date();
            d.setHours(0, 0, 0, 0);

            return d;
        }

        function pintarCampo() {
            var d = dataDe(input.value);
            txt.textContent = d ? d.toLocaleDateString('pt-BR') : 'dd/mm/aaaa';
            campo.classList.toggle('tem-valor', !!d);
        }

        /* A grade do desenho: começa no domingo da semana do dia 1; 5 semanas, ou 6 quando
           o mês ainda não acabou na 35ª casa. */
        function desenhar() {
            var h = hoje();
            var hojeIso = isoDe(h);
            mesTxt.textContent = MESES[base.getMonth()] + ' de ' + base.getFullYear();
            dias.textContent = '';
            var ini = new Date(base);
            ini.setDate(1 - base.getDay());
            for (var i = 0; i < 42; i++) {
                var d = new Date(ini);
                d.setDate(ini.getDate() + i);
                if (i === 35 && d.getMonth() !== base.getMonth()) { break; }
                var iso = isoDe(d);
                var passado = d < h;
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ps-nm-cal-dia'
                    + (d.getMonth() !== base.getMonth() ? ' is-fora' : '')
                    + (d.getDay() % 6 === 0 ? ' is-fds' : '')
                    + (iso === hojeIso ? ' is-hoje' : '');
                b.textContent = String(d.getDate());
                b.disabled = passado;
                b.setAttribute('aria-pressed', input.value === iso ? 'true' : 'false');
                b.setAttribute('aria-label', d.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' }) + (passado ? ', indisponível' : ''));
                b.setAttribute('data-ps-nm-cal-dia', iso);
                dias.appendChild(b);
            }
        }

        function aberto() { return !cal.hidden; }

        function abrir() {
            var d = dataDe(input.value) || hoje();
            base = new Date(d.getFullYear(), d.getMonth(), 1);
            desenhar();
            cal.hidden = false;
            campo.setAttribute('aria-expanded', 'true');
            var foco = dias.querySelector('[aria-pressed="true"]') || dias.querySelector('.is-hoje') || dias.querySelector('button:not(:disabled)');
            if (foco) { foco.focus(); }
        }

        function fechar(devolverFoco) {
            if (!aberto()) { return; }
            cal.hidden = true;
            campo.setAttribute('aria-expanded', 'false');
            if (devolverFoco) { campo.focus(); }
        }

        function escolher(iso) {
            input.value = iso;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            pintarCampo();
            fechar(true);
        }

        function mudarMes(n) {
            base = new Date(base.getFullYear(), base.getMonth() + n, 1);
            desenhar();
        }

        campo.addEventListener('click', function () {
            if (aberto()) { fechar(false); } else { abrir(); }
        });
        cal.addEventListener('click', function (e) {
            var dia = e.target.closest('[data-ps-nm-cal-dia]');
            if (dia && !dia.disabled) { escolher(dia.getAttribute('data-ps-nm-cal-dia')); return; }
            if (e.target.closest('[data-ps-nm-cal-ant]')) { mudarMes(-1); return; }
            if (e.target.closest('[data-ps-nm-cal-prox]')) { mudarMes(1); return; }
            if (e.target.closest('[data-ps-nm-cal-hoje]')) { escolher(isoDe(hoje())); return; }
            if (e.target.closest('[data-ps-nm-cal-limpar]')) { escolher(''); }
        });
        // Esc fecha o CALENDÁRIO, não o modal.
        cal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                fechar(true);
            }
        });
        document.addEventListener('click', function (e) {
            if (aberto() && !cal.contains(e.target) && !campo.contains(e.target)) { fechar(false); }
        }, true);

        // Atalhos, Push, IA e a observação escrevem no input: o botão acompanha.
        input.addEventListener('input', pintarCampo);
        input.addEventListener('change', pintarCampo);
        if (modalMeta) {
            modalMeta.addEventListener('show.bs.modal', pintarCampo);
            modalMeta.addEventListener('hidden.bs.modal', function () { fechar(false); pintarCampo(); });
        }
        pintarCampo();
    }

    /* ── 4. Confirmação e aviso ───────────────────────────────────────────── */
    var confirmacaoAberta = null; // { resolver, foco, lugar }

    /* PAS-034b: perguntar com um modal do Bootstrap aberto (o "Descartar as alterações?" do
       Editar dados). O focus trap do modal puxa de volta o foco que sai dele, e o Esc chegaria
       ao modal: a janela de confirmação mora DENTRO do modal aberto enquanto pergunta e volta
       ao lugar dela ao fechar. O `.modal` não tem transform (quem tem é o `.modal-dialog`),
       então o `position: fixed` do fundo continua sendo da tela. Devolve o lugar original. */
    function hospedarNoModalAberto(fundo) {
        // Só modal de verdade: a Nova meta (`data-bs-focus="false"`, C6-PMC-01) não prende o foco e
        // tem `pointer-events: none`, que a janela herdaria — o clique atravessaria (C7-PAS-01).
        var modal = document.querySelector('.modal.show:not([data-bs-focus="false"])');
        if (!modal || modal.contains(fundo)) { return null; }
        var lugar = { pai: fundo.parentNode, proximo: fundo.nextSibling };
        modal.appendChild(fundo);

        return lugar;
    }

    /**
     * Abre a janela de confirmação. `opcoes`: { titulo, texto, rotulo, icone, variante, foco }.
     * `foco`: para onde o foco volta ao fechar (padrão: o elemento focado ao abrir).
     * `variante` 'azul' (ação sem perigo: botão #0a6fa0, padrão ✓ "Confirmar") ou 'perigo' (padrão:
     * botão vermelho, 🗑 "Excluir"). `moldura` 'alerta': a janela do dc 03 L.259-271 (faixa vermelha, círculo
     * com ⚠, tudo centralizado, sem o X), usada na exclusão de marcador do Expediente. Devolve uma Promise que resolve `true` só se a pessoa confirmar.
     */
    function confirmarPs(opcoes) {
        var o = opcoes || {};
        var fundo = document.getElementById('psConfirmar');
        if (!fundo) {
            return Promise.resolve(window.confirm([o.titulo, o.texto].filter(Boolean).join('\n\n')));
        }
        if (confirmacaoAberta) { encerrarConfirmacao(false); }
        var caixa = fundo.querySelector('.ps-cf');
        var ok = fundo.querySelector('[data-ps-cf-ok]');
        var azul = o.variante === 'azul';
        caixa.classList.toggle('is-azul', azul);
        fundo.classList.toggle('is-alerta', o.moldura === 'alerta');
        document.getElementById('psConfirmarTitulo').textContent = o.titulo || 'Confirmar';
        document.getElementById('psConfirmarTexto').textContent = o.texto || '';
        ok.querySelector('span').textContent = o.rotulo || (azul ? 'Confirmar' : 'Excluir');
        ok.querySelector('i').className = 'bi ' + (o.icone || (azul ? 'bi-check2' : 'bi-trash3'));
        var lugar = hospedarNoModalAberto(fundo);
        fundo.hidden = false;

        // PAS-034c: o foco inicial vai para o "Cancelar", não para a caixa — com a caixa focada, um
        // Enter perdido (ainda do clique/tecla que abriu a janela) confirmava a exclusão.
        var cancelar = fundo.querySelector('.ps-cf-rodape [data-ps-cf-cancelar]') || caixa;

        return new Promise(function (resolver) {
            confirmacaoAberta = { resolver: resolver, foco: o.foco || document.activeElement, lugar: lugar };
            setTimeout(function () { cancelar.focus(); }, 30);
        });
    }

    /* E7: o foco volta para quem abriu a pergunta. Se quem abriu era um item de menu que já fechou
       (Duplicar, Excluir meta: o ⋮ some ao clicar), o foco iria para o <body> e o teclado perderia o
       lugar — volta então para o botão que abre aquele menu: `data-ps-pop="<id>"` (menus `.ps-pop`),
       `aria-controls="<id>"` (menus `role="menu"`, como o ⋮ dos processos) ou, no menu sem id (análises
       da IA), o `[aria-haspopup]` irmão dele no mesmo invólucro. */
    function visivel(el) {
        return !!(el && document.contains(el) && el.getClientRects().length > 0);
    }

    function devolverFoco(foco) {
        if (!foco || !foco.focus) { return; }
        if (visivel(foco)) { foco.focus(); return; }
        var menu = foco.closest ? foco.closest('.ps-pop, [role="menu"]') : null;
        var id = menu && menu.id ? menu.id.replace(/["\\]/g, '') : '';
        var gatilho = id ? document.querySelector('[data-ps-pop="' + id + '"], [aria-controls="' + id + '"]') : null;
        if (!gatilho && menu && menu.parentElement) { gatilho = menu.parentElement.querySelector(':scope > [aria-haspopup]'); }
        if (visivel(gatilho)) { gatilho.focus(); }
    }

    function encerrarConfirmacao(resposta) {
        var fundo = document.getElementById('psConfirmar');
        if (!fundo || !confirmacaoAberta) { return; }
        var aberta = confirmacaoAberta;
        confirmacaoAberta = null;
        fundo.hidden = true;
        if (aberta.lugar && aberta.lugar.pai) {
            // O vizinho pode ter saído do DOM enquanto a janela estava no modal: sem ele, vai ao fim.
            var proximo = aberta.lugar.proximo;
            if (proximo && proximo.parentNode === aberta.lugar.pai) { aberta.lugar.pai.insertBefore(fundo, proximo); }
            else { aberta.lugar.pai.appendChild(fundo); }
        }
        devolverFoco(aberta.foco);
        aberta.resolver(resposta);
    }

    function ligarConfirmacao() {
        var fundo = document.getElementById('psConfirmar');
        if (!fundo) { return; }
        fundo.addEventListener('click', function (e) {
            if (e.target === fundo || e.target.closest('[data-ps-cf-cancelar]')) { encerrarConfirmacao(false); return; }
            if (e.target.closest('[data-ps-cf-ok]')) { encerrarConfirmacao(true); }
        });
        fundo.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                encerrarConfirmacao(false);
            } else if (e.key === 'Enter' && e.target.closest('[data-ps-cf-cancelar]')) {
                // Enter no Cancelar (onde o foco nasce) CANCELA — explícito, sem depender do clique nativo.
                e.preventDefault();
                encerrarConfirmacao(false);
            } else if (e.key === 'Enter' && !e.target.closest('[data-ps-cf-cancelar]')) {
                e.preventDefault();
                encerrarConfirmacao(true);
            } else if (e.key === 'Tab') {
                // O foco não sai da janela enquanto ela está aberta.
                var botoes = Array.prototype.slice.call(fundo.querySelectorAll('button')).filter(visivel);
                var i = botoes.indexOf(document.activeElement);
                e.preventDefault();
                botoes[(i + (e.shiftKey ? -1 : 1) + botoes.length) % botoes.length].focus();
            }
        });
    }

    /* ── 5. A regra do desenho para uma mensagem de `confirm` ─────────────── */
    /**
     * dc `confirmarUI(msg, ok, perigo)`: se a mensagem tem quebra de linha nos primeiros 90 caracteres,
     * o que vem antes é o título e o resto é o texto; senão o título é "Confirmar exclusão" (perigo) ou
     * "Confirmar" e a mensagem inteira é o texto. Botão "Confirmar": vermelho com ⚠ no perigo, azul com
     * ✓ fora dele. Sem a janela na página, cai no `confirm` do navegador com a mesma mensagem.
     */
    function opcoesDaMensagem(mensagem, perigo) {
        var t = String(mensagem || '');
        var i = t.indexOf('\n');
        var temTitulo = i > 0 && i < 90;

        return {
            titulo: temTitulo ? t.slice(0, i).trim() : (perigo ? 'Confirmar exclusão' : 'Confirmar'),
            texto: (temTitulo ? t.slice(i + 1) : t).trim(),
            rotulo: 'Confirmar',
            icone: perigo ? 'bi-exclamation-triangle' : 'bi-check2',
            variante: perigo ? 'perigo' : 'azul'
        };
    }

    function confirmarUIPs(mensagem, perigo, foco) {
        if (!document.getElementById('psConfirmar')) {
            return Promise.resolve(window.confirm(String(mensagem || '')));
        }
        var opcoes = opcoesDaMensagem(mensagem, !!perigo);
        if (foco) { opcoes.foco = foco; }

        return confirmarPs(opcoes);
    }

    /* ── 6. Envio de formulário com confirmação ─────────────────────────── */
    var perguntasDeEnvio = new WeakMap(); // form → function (form, submitter) → { mensagem, perigo } | null
    var envioConfirmado = new WeakSet();

    function psConfirmarEnvio(form, perguntar) {
        if (form && typeof perguntar === 'function') { perguntasDeEnvio.set(form, perguntar); }
    }

    function perguntaDoEnvio(form, submitter) {
        if (perguntasDeEnvio.has(form)) { return perguntasDeEnvio.get(form)(form, submitter); }
        if (form.hasAttribute('data-ps-cf')) {
            return { mensagem: form.getAttribute('data-ps-cf'), perigo: form.hasAttribute('data-ps-cf-perigo') };
        }

        return null;
    }

    function ligarEnvioConfirmado() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) { return; }
            // O reenvio depois do "sim": deixa passar uma vez, para os ouvintes de sempre.
            if (envioConfirmado.has(form)) { envioConfirmado.delete(form); return; }
            var pergunta = perguntaDoEnvio(form, e.submitter || null);
            if (!pergunta) { return; }

            // Nada do formulário roda antes da resposta: nem o envio, nem o XHR, nem o `onsubmit`.
            e.preventDefault();
            e.stopImmediatePropagation();
            var submitter = e.submitter && e.submitter.form === form ? e.submitter : null;
            confirmarUIPs(pergunta.mensagem, pergunta.perigo).then(function (sim) {
                if (!sim || !document.contains(form)) { return; }
                // Navegador sem `requestSubmit` (Safari < 16): `submit()` envia sem disparar `submit` —
                // os ouvintes do formulário não rodam. Os formulários com `data-ps-cf` são POST comuns.
                if (typeof form.requestSubmit !== 'function') { form.submit(); return; }
                // O botão pode ter saído do formulário enquanto a pessoa lia: aí o envio vai sem ele.
                var botao = submitter && submitter.form === form && document.contains(submitter) ? submitter : null;
                envioConfirmado.add(form);
                try {
                    if (botao) { form.requestSubmit(botao); } else { form.requestSubmit(); }
                } finally {
                    // `requestSubmit` dispara o `submit` na hora; se a validação o barrou ou ele lançou,
                    // a marca não pode sobrar para liberar o PRÓXIMO envio sem pergunta.
                    envioConfirmado.delete(form);
                }
            });
        }, true);
    }

    var avisoTimer = 0;

    /**
     * PAS-034c: as regiões vivas do aviso nascem JÁ na carga da página, vazias e sempre presentes
     * (`status` educado e `alert` assertivo). Leitor de tela só anuncia a MUDANÇA de conteúdo de
     * uma região que já existia; trocar o `role` de um elemento `hidden` no mesmo instante em que
     * ele aparece não é anunciado de forma confiável. O `#psAviso` vira só o visual (aria-hidden),
     * para a mensagem não ser lida duas vezes.
     */
    function criarRegioesVivas() {
        var aviso = document.getElementById('psAviso');
        if (!aviso) { return null; }
        aviso.removeAttribute('role');
        aviso.removeAttribute('aria-live');
        aviso.setAttribute('aria-hidden', 'true');

        function regiao(papel, vivo) {
            var r = document.createElement('div');
            r.className = 'visually-hidden';
            r.setAttribute('role', papel);
            r.setAttribute('aria-live', vivo);
            r.setAttribute('aria-atomic', 'true');
            r.setAttribute('data-ps-aviso-vivo', papel);
            document.body.appendChild(r);

            return r;
        }

        return { status: regiao('status', 'polite'), alerta: regiao('alert', 'assertive') };
    }

    var regioesVivas = criarRegioesVivas();
    var vivoTimer = 0;

    /** Aviso no rodapé da tela por 2,6 s (o `gmAviso` do desenho). `tipo`: 'ok' (padrão) ou 'erro'. */
    function avisarPs(mensagem, tipo) {
        var aviso = document.getElementById('psAviso');
        if (!aviso) { window.alert(mensagem); return; }
        var erro = tipo === 'erro';
        var texto = String(mensagem || '');
        aviso.classList.toggle('is-erro', erro);
        aviso.querySelector('i').className = 'bi ' + (erro ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill');
        aviso.querySelector('span').textContent = texto;
        if (regioesVivas) {
            // Esvazia e escreve no tique seguinte: a mesma mensagem duas vezes seguidas é anunciada de novo.
            var alvo = erro ? regioesVivas.alerta : regioesVivas.status;
            regioesVivas.status.textContent = '';
            regioesVivas.alerta.textContent = '';
            clearTimeout(vivoTimer);
            vivoTimer = setTimeout(function () { alvo.textContent = texto; }, 50);
        }
        aviso.hidden = false;
        clearTimeout(avisoTimer);
        avisoTimer = setTimeout(function () { aviso.hidden = true; }, 2600);
    }

    window.confirmarPs = confirmarPs;
    window.confirmarUIPs = confirmarUIPs;
    window.psConfirmarEnvio = psConfirmarEnvio;
    window.avisarPs = avisarPs;

    contadores();
    seletorDeResponsaveis();
    calendarioDoPrazo();
    ligarConfirmacao();
    ligarEnvioConfirmado();
})();
