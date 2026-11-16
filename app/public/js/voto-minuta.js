/* BlueJus Voto: "Minuta de ata" (desenho 18). O formulário [data-voto-minuta] baixa o .docx ou o PDF por fetch e mostra o
   toast do desenho ("Minuta de ata gerada." / "... no papel timbrado."); se o servidor recusar (sem votação apurada,
   modelo inválido) ele devolve ao painel com o aviso e o navegador segue o redirecionamento. O modelo .docx (campo
   `timbre`) acima de 2,5 MB é recusado aqui, antes de enviar, com a mesma mensagem do servidor. Sem JS o formulário baixa igual. */
(function () {
    'use strict';

    var LIMITE_DO_MODELO = 2500000;

    function caixa() {
        var c = document.querySelector('[data-voto-toasts]');
        if (!c) {
            c = document.createElement('div');
            c.className = 'voto-toasts';
            c.setAttribute('data-voto-toasts', '');
            document.body.appendChild(c);
        }
        return c;
    }

    function avisar(texto, erro) {
        var t = document.createElement('div');
        t.className = 'voto-toast' + (erro ? ' voto-toast-erro' : '');
        t.setAttribute('role', 'status');
        t.textContent = texto;
        caixa().appendChild(t);
        window.setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 3600);
    }

    function nomeDoArquivo(resposta) {
        var m = /filename="?([^";]+)"?/.exec(resposta.headers.get('Content-Disposition') || '');
        return m ? m[1] : 'minuta-ata';
    }

    function salvar(blob, nome) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = nome;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }

    function enviar(form, acao, comModelo) {
        var dados = new FormData(form);
        if (!comModelo) { dados.delete('timbre'); }
        var timbrada = comModelo && form.querySelector('[data-voto-timbre]').files.length > 0;
        fetch(acao, { method: 'POST', body: dados, credentials: 'same-origin' }).then(function (resposta) {
            var tipo = resposta.headers.get('Content-Type') || '';
            if (resposta.ok && resposta.redirected && tipo.indexOf('text/html') === 0) {
                window.location.href = resposta.url;
                return undefined;
            }
            if (!resposta.ok || tipo.indexOf('text/html') === 0) { throw new Error('sem arquivo'); }
            return resposta.blob().then(function (blob) {
                salvar(blob, nomeDoArquivo(resposta));
                avisar(form.getAttribute(timbrada ? 'data-voto-sucesso-timbrado' : 'data-voto-sucesso'), false);
            });
        }).catch(function () {
            avisar(form.getAttribute('data-voto-erro'), true);
        });
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target.closest ? ev.target.closest('[data-voto-minuta]') : null;
        if (!form || !window.fetch || !window.FormData) { return; }
        ev.preventDefault();
        var botao = ev.submitter;
        var acao = botao && botao.getAttribute('formaction') ? botao.getAttribute('formaction') : form.getAttribute('action');
        enviar(form, acao, acao === form.getAttribute('action'));
    });

    document.addEventListener('change', function (ev) {
        var campo = ev.target;
        if (!campo.matches || !campo.matches('[data-voto-timbre]')) { return; }
        var texto = campo.parentNode.querySelector('[data-voto-timbre-texto]');
        var arquivo = campo.files[0];
        if (arquivo && arquivo.size > LIMITE_DO_MODELO) {
            avisar(campo.form.getAttribute('data-voto-grande'), true);
            campo.value = '';
            arquivo = null;
        }
        texto.textContent = arquivo
            ? campo.getAttribute('data-voto-timbre-modelo').replace('__NOME__', arquivo.name)
            : campo.getAttribute('data-voto-timbre-padrao');
    });
}());
