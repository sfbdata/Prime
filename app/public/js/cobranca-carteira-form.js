/**
 * Formulário de carteira (criar/editar configuração):
 * - inicializa os popovers de ajuda (ícone "?") dos campos modo/forma de honorários;
 * - esconde o campo de percentual quando a forma escolhida é "sem percentual".
 * Carregado nas telas de lista (modal criar) e detalhe (modal editar) da carteira.
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof bootstrap === 'undefined') { return; }

    document.querySelectorAll('.cobranca-ajuda[data-bs-toggle="popover"]').forEach(function (el) {
        new bootstrap.Popover(el, { container: 'body' });
    });

    document.querySelectorAll('[data-forma-honorarios]').forEach(function (select) {
        var form = select.closest('form');
        if (!form) { return; }
        var wrapper = form.querySelector('[data-percentual-wrapper]');
        if (!wrapper) { return; }
        var input = wrapper.querySelector('input');

        function atualizar() {
            var semPercentual = (select.value === 'sem_percentual');
            wrapper.style.display = semPercentual ? 'none' : '';
            // Desabilitar quando oculto: campo desabilitado não é enviado no POST,
            // evitando persistir um percentual órfão junto com "sem percentual".
            if (input) { input.disabled = semPercentual; }
        }

        select.addEventListener('change', atualizar);
        atualizar();
    });
});
