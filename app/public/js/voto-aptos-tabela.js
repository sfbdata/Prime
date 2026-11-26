/* BlueJus Voto: tabela simples da etapa "Lista de aptos". "Adicionar linha" copia a linha modelo (<template>) e o lixo
   remove a linha (sempre sobra uma). O envio é o do formulário: o servidor monta o texto da importação com as linhas.
   Sem JavaScript a tabela abre com 3 linhas e o formulário funciona do mesmo jeito. */
(function () {
    'use strict';

    var form = document.querySelector('[data-voto-aptos]');
    if (!form) {
        return;
    }
    var corpo = form.querySelector('[data-voto-aptos-linhas]');
    var modelo = form.querySelector('[data-voto-aptos-modelo]');
    if (!corpo || !modelo) {
        return;
    }

    form.addEventListener('click', function (evento) {
        var alvo = evento.target instanceof Element ? evento.target : null;
        if (!alvo) {
            return;
        }
        if (alvo.closest('[data-voto-aptos-adicionar]')) {
            corpo.appendChild(modelo.content.cloneNode(true));
            var linhas = corpo.querySelectorAll('[data-voto-aptos-linha]');
            var primeiro = linhas[linhas.length - 1].querySelector('input');
            if (primeiro) {
                primeiro.focus();
            }
            return;
        }
        var remover = alvo.closest('[data-voto-aptos-remover]');
        if (remover) {
            var linha = remover.closest('[data-voto-aptos-linha]');
            if (corpo.querySelectorAll('[data-voto-aptos-linha]').length > 1) {
                linha.remove();
            } else {
                linha.querySelectorAll('input').forEach(function (campo) {
                    campo.value = '';
                });
            }
        }
    });
}());
