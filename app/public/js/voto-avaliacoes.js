/* Ocorrências e avaliações do conselho (spec voto-avaliacoes-do-conselho-1611; desenho 18, H249 e H273): a busca da lista de
   unidades e os botões que só habilitam com o texto preenchido. Sem JavaScript tudo funciona: o servidor recusa o campo vazio. */
(function () {
    'use strict';

    function normalizar(texto) {
        var t = String(texto || '').toLowerCase();
        return t.normalize ? t.normalize('NFD').replace(/[̀-ͯ]/g, '') : t;
    }

    function filtrarUnidades(campo) {
        var busca = normalizar(campo.value).trim();
        var vazio = document.querySelector('[data-voto-busca-vazia]');
        var achou = 0;
        Array.prototype.forEach.call(document.querySelectorAll('[data-voto-unidade-busca]'), function (linha) {
            var casa = '' === busca || normalizar(linha.getAttribute('data-voto-unidade-busca')).indexOf(busca) >= 0;
            linha.hidden = !casa;
            achou += casa ? 1 : 0;
        });
        if (vazio) {
            vazio.hidden = '' === busca || achou > 0;
        }
    }

    /* Botões do formulário habilitam com o campo preenchido; os marcados "sempre desabilitado" seguem desabilitados. */
    function sincronizar(form) {
        var campo = form.querySelector('[data-voto-exige-texto]');
        if (!campo) {
            return;
        }
        var vazio = '' === campo.value.trim();
        Array.prototype.forEach.call(form.querySelectorAll('button[data-voto-exige-botao]'), function (botao) {
            botao.disabled = vazio || botao.hasAttribute('data-voto-sempre-desabilitado');
        });
    }

    document.addEventListener('input', function (evento) {
        var alvo = evento.target;
        if (!alvo || !alvo.matches) {
            return;
        }
        if (alvo.matches('[data-voto-busca-unidade]')) {
            filtrarUnidades(alvo);
        }
        if (alvo.matches('[data-voto-exige-texto]') && alvo.form) {
            sincronizar(alvo.form);
        }
    });

    function iniciar() {
        Array.prototype.forEach.call(document.querySelectorAll('form'), sincronizar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
