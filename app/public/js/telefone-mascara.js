/**
 * Máscara de digitação de telefone brasileiro (2026-07-28).
 *
 * `(99) 9999-9999` para 10 dígitos e `(99) 99999-9999` para 11 — as mesmas do filtro Twig
 * `telefone_br`, que é quem formata na EXIBIÇÃO. Duas implementações da mesma máscara são inevitáveis
 * (uma no servidor, outra na digitação); o que não pode é divergirem — se mexer numa, mexa na outra.
 *
 * Age por DELEGAÇÃO no `document`, e não por campo: a lista de telefones da aba Responsáveis é trocada
 * inteira pela resposta AJAX de adicionar/corrigir/excluir, e um listener preso ao input morreria junto
 * com o campo antigo. Marcador: `data-mascara="telefone"`.
 *
 * NÃO briga com dado legado: acima de 11 dígitos o valor é deixado exatamente como está. Existe número
 * de 12 dígitos gravado (importação torta), e uma máscara que trunca em silêncio faria o gestor salvar
 * um número mutilado achando que só corrigiu o DDD.
 */
(function () {
    'use strict';

    var LIMITE_DIGITOS = 11;

    function apenasDigitos(valor) {
        return (valor || '').replace(/\D/g, '');
    }

    /** Dígitos (não caracteres) à esquerda do cursor — é o que sobrevive à reformatação. */
    function digitosAntesDoCursor(valor, posicao) {
        return apenasDigitos(valor.slice(0, posicao)).length;
    }

    /** Posição, no texto já formatado, logo depois do n-ésimo dígito. */
    function posicaoDepoisDoDigito(formatado, n) {
        if (n <= 0) {
            return 0;
        }

        var contados = 0;
        for (var i = 0; i < formatado.length; i++) {
            if (formatado[i] >= '0' && formatado[i] <= '9') {
                contados++;
                if (contados === n) {
                    return i + 1;
                }
            }
        }

        return formatado.length;
    }

    /**
     * Formata o que já foi digitado, sem completar nada. Enquanto o número está incompleto a máscara
     * cresce junto: `(21`, `(21) 9999`, `(21) 99999-2222`.
     */
    function formatar(digitos) {
        if (digitos.length === 0) {
            return '';
        }
        if (digitos.length <= 2) {
            return '(' + digitos;
        }
        if (digitos.length <= 6) {
            return '(' + digitos.slice(0, 2) + ') ' + digitos.slice(2);
        }
        // Com 11 dígitos o bloco do meio tem 5 (celular); com até 10, tem 4 (fixo).
        var meio = digitos.length > 10 ? 5 : 4;

        return '(' + digitos.slice(0, 2) + ') ' + digitos.slice(2, 2 + meio) + '-' + digitos.slice(2 + meio);
    }

    function aplicar(campo) {
        var digitos = apenasDigitos(campo.value);

        // Legado com mais dígitos do que qualquer telefone brasileiro: não é nosso, não mexemos.
        if (digitos.length > LIMITE_DIGITOS) {
            return;
        }

        var formatado = formatar(digitos);
        if (formatado === campo.value) {
            return;
        }

        var digitosAteOCursor = digitosAntesDoCursor(campo.value, campo.selectionStart || 0);
        campo.value = formatado;

        // `setSelectionRange` estoura em input de tipo que não suporta seleção; aqui é sempre `text`,
        // mas o try mantém a máscara inofensiva caso alguém marque outro campo com o mesmo atributo.
        try {
            var posicao = posicaoDepoisDoDigito(formatado, digitosAteOCursor);
            campo.setSelectionRange(posicao, posicao);
        } catch (e) { /* campo sem suporte a seleção — a formatação já valeu */ }
    }

    document.addEventListener('input', function (evento) {
        var campo = evento.target;
        if (campo && campo.matches && campo.matches('input[data-mascara="telefone"]')) {
            aplicar(campo);
        }
    });
})();
