/**
 * Diálogos da tela da pasta (lote L6). Fica num arquivo próprio para não tocar o pasta-show.js.
 *
 * 1. Contador de caracteres (PMC-039): `[data-ps-nm-contador="<id do campo>"]` mostra "N/limite"
 *    com o `maxlength` do próprio campo.
 * 2. Responsáveis da Nova meta (PMC-041; desenho dc L.168-186): chips sobre o campo e a lista
 *    filtrável. O `<select multiple>` (`#tarefaResponsavel`) é a FONTE: aqui só se marcam e
 *    desmarcam as `<option>` dele, e o envio do formulário continua o de sempre.
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

    contadores();
    seletorDeResponsaveis();
})();
