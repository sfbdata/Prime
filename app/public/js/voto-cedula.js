/*
 * Cédula da assembleia de condomínio (/votar/{token}). Vanilla, sem dependência, sem Bootstrap.
 *
 * - Escolher uma opção abre a janela de confirmação do desenho (.vMod): clique no fundo, "Fechar", "Corrigir" ou Esc
 *   fecham; "Confirmar voto" envia o formulário da pauta com a opção escolhida. Sem JavaScript, o botão da opção
 *   envia direto (o servidor confere tudo do mesmo jeito).
 * - "Encerra em mm:ss." conta para trás a partir dos segundos que o servidor mandou (não usa o relógio do aparelho);
 *   ao zerar, recarrega a página, que mostra a pauta encerrada.
 * - Com pauta à espera da mesa (body[data-vc-recarregar]), recarrega a cada N segundos, nunca com a janela aberta.
 * - O toast some em 3600 ms, como no desenho.
 *
 * Nada aqui escreve HTML vindo de fora: só textos que o servidor já pôs em atributos data-*.
 */
(function () {
    'use strict';

    var janela = document.querySelector('[data-vc-janela]');
    var formulario = null;
    var escolha = null;

    function janelaAberta() {
        return !!(janela && !janela.hidden);
    }

    function fechar() {
        if (!janela) {
            return;
        }
        janela.hidden = true;
        if (escolha) {
            escolha.focus();
        }
        formulario = null;
        escolha = null;
    }

    function abrir(form, botao) {
        formulario = form;
        escolha = botao;
        janela.querySelector('[data-vc-janela-pauta]').textContent = form.getAttribute('data-vc-pauta-titulo') || '';
        janela.querySelector('[data-vc-janela-escolha]').textContent = botao.getAttribute('data-vc-escolha') || '';
        janela.hidden = false;
        janela.querySelector('[data-vc-confirmar]').focus();
    }

    function confirmar() {
        if (!formulario || !escolha) {
            return;
        }
        var anterior = formulario.querySelector('input[type="hidden"][name="opcao"]');
        if (anterior) {
            anterior.remove();
        }
        var campo = document.createElement('input');
        campo.type = 'hidden';
        campo.name = 'opcao';
        campo.value = escolha.value;
        formulario.appendChild(campo);
        janela.querySelector('[data-vc-confirmar]').disabled = true;
        formulario.submit();
    }

    if (janela) {
        document.querySelectorAll('form[data-vc-votar]').forEach(function (form) {
            form.addEventListener('submit', function (evento) {
                var botao = evento.submitter;
                if (!botao || !botao.hasAttribute('data-vc-escolha')) {
                    return;
                }
                evento.preventDefault();
                abrir(form, botao);
            });
        });
        // Clique no fundo fecha; o conteúdo para a propagação (como o `pare` do desenho).
        janela.addEventListener('click', function (evento) {
            if (evento.target === janela) {
                fechar();
            }
        });
        janela.querySelector('.vModC').addEventListener('click', function (evento) {
            evento.stopPropagation();
        });
        janela.querySelectorAll('[data-vc-fechar]').forEach(function (botao) {
            botao.addEventListener('click', fechar);
        });
        janela.querySelector('[data-vc-confirmar]').addEventListener('click', confirmar);
        document.addEventListener('keydown', function (evento) {
            if (evento.key === 'Escape' && janelaAberta()) {
                fechar();
            }
        });
    }

    function doisDigitos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    var inicio = Date.now();
    var prazos = Array.prototype.slice.call(document.querySelectorAll('[data-vc-prazo]'));
    var recarregando = false;
    var zerou = false;

    function recarregar() {
        if (recarregando) {
            return;
        }
        if (janelaAberta()) {
            setTimeout(recarregar, 2000);
            return;
        }
        recarregando = true;
        window.location.reload();
    }

    function contar() {
        var passados = Math.floor((Date.now() - inicio) / 1000);
        prazos.forEach(function (el) {
            var restam = Math.max(0, parseInt(el.getAttribute('data-vc-prazo'), 10) - passados);
            var texto = el.querySelector('[data-vc-prazo-texto]');
            var modelo = el.getAttribute('data-vc-modelo') || '';
            if (texto) {
                texto.textContent = modelo.replace('{t}', doisDigitos(Math.floor(restam / 60)) + ':' + doisDigitos(restam % 60));
            }
            if (restam === 0 && !zerou) {
                zerou = true;
                setTimeout(recarregar, 1000);
            }
        });
    }

    if (prazos.length > 0) {
        setInterval(contar, 1000);
    }

    var intervalo = parseInt(document.body.getAttribute('data-vc-recarregar') || '', 10);
    if (intervalo > 0) {
        setTimeout(recarregar, intervalo * 1000);
    }

    document.querySelectorAll('[data-vc-toast]').forEach(function (toast) {
        setTimeout(function () {
            toast.hidden = true;
        }, 3600);
    });
})();
