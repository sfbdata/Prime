/* BlueJus Voto: assistente de 5 passos, passo que é um formulário (1) e abertura da urna do passo Cargos. Mostra "Alterações ainda não salvas" na
   faixa do assistente (desenho 18, L254) assim que um campo muda e avisa o navegador antes de sair da página com
   alteração (beforeunload, como o editor do desenho). Enviar o formulário (qualquer botão do trilho ou do rodapé) grava
   e libera a saída. Sem JavaScript o formulário funciona do mesmo jeito. O passo Cargos tem o próprio controle
   (voto-assembleia.js, que grava as pautas ao sair). */
(function () {
    'use strict';

    /* "Ver na urna" (passo Cargos): a página recarrega com as pautas já gravadas e abre o treino da cabine (voto-cabine.js,
       carregado antes deste arquivo). O botão escondido é o mesmo "Simular votação" da aba Votar: nenhum voto é registrado. */
    var urna = document.querySelector('[data-voto-abrir-urna]');
    if (urna) {
        urna.click();
    }

    var form = document.querySelector('form[data-voto-assistente-form]');
    if (!form) {
        return;
    }
    var faixa = document.querySelector('[data-voto-sujo]');
    var sujo = false;
    var enviando = false;

    function marcar() {
        sujo = true;
        if (faixa) {
            faixa.hidden = false;
        }
    }

    form.addEventListener('input', marcar);
    form.addEventListener('change', marcar);
    form.addEventListener('submit', function () {
        enviando = true;
    });

    window.addEventListener('beforeunload', function (evento) {
        if (!sujo || enviando) {
            return;
        }
        evento.preventDefault();
        evento.returnValue = '';
    });

    /* Voltar pelo histórico com a página guardada (bfcache): a tela volta a aceitar o envio. */
    window.addEventListener('pageshow', function () {
        enviando = false;
    });
}());
