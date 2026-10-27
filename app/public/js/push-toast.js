/* Toast do Push Processual (desenho 11): faixa escura no rodapé por 3,2 s. Os flashes do servidor viram toast. */
(function () {
    'use strict';
    var temporizador = null;

    function toast(mensagem) {
        var antigo = document.querySelector('.pp-toast');
        if (antigo) { antigo.remove(); }
        var div = document.createElement('div');
        div.className = 'pp-toast';
        div.setAttribute('role', 'status');
        div.textContent = mensagem;
        document.body.appendChild(div);
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(function () { div.remove(); }, 3200);
    }

    window.ppToast = toast;

    document.addEventListener('DOMContentLoaded', function () {
        var flashes = document.querySelectorAll('.app-content-header .alert');
        if (flashes.length === 0) { return; }
        var texto = Array.prototype.map.call(flashes, function (f) {
            var copia = f.cloneNode(true);
            copia.querySelectorAll('button').forEach(function (b) { b.remove(); });
            f.hidden = true;
            return copia.textContent.trim();
        }).filter(Boolean).join(' ');
        if (texto) { toast(texto); }
    });
}());
