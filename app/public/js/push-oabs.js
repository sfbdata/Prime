/* OABs monitoradas (Push): modal "Editar apelido". O formulário posta no servidor; aqui só se abre e se preenche. */
(function () {
    var modal = document.querySelector('[data-pp-oab-modal]');
    if (!modal) { return; }
    var form = modal.querySelector('[data-pp-oab-form]');
    var campo = modal.querySelector('[data-pp-oab-apelido]');

    function abrir(botao) {
        form.action = botao.getAttribute('data-url');
        modal.querySelector('[data-pp-oab-token]').value = botao.getAttribute('data-token');
        modal.querySelector('[data-pp-oab-rotulo]').textContent = botao.getAttribute('data-oab');
        campo.value = botao.getAttribute('data-apelido') || '';
        modal.hidden = false;
        campo.focus();
        campo.select();
    }

    function fechar() { modal.hidden = true; }

    document.querySelectorAll('[data-pp-oab-editar]').forEach(function (botao) {
        botao.addEventListener('click', function () { abrir(botao); });
    });
    modal.querySelectorAll('[data-pp-oab-fechar]').forEach(function (el) {
        el.addEventListener('click', fechar);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { fechar(); }
    });
}());
