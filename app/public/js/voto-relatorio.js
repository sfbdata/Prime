/* BlueJus Voto: janela "Relatório" (desenho 18). Clique numa opção [data-voto-relatorio]: fecha a janela e mostra o
   toast de sucesso (data-voto-sucesso). Nas opções [data-voto-baixar] (resumo em imagem e PDF) o arquivo vem por fetch:
   só dá sucesso se a resposta for mesmo o arquivo; senão mostra o erro (data-voto-erro). Sem JS o link baixa igual. */
(function () {
    'use strict';

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
        return m ? m[1] : 'resumo';
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

    function baixar(link) {
        fetch(link.href, { credentials: 'same-origin' }).then(function (resposta) {
            var tipo = resposta.headers.get('Content-Type') || '';
            if (!resposta.ok || (tipo.indexOf('image/') !== 0 && tipo.indexOf('application/pdf') !== 0)) {
                throw new Error('sem arquivo');
            }
            return resposta.blob().then(function (blob) {
                salvar(blob, nomeDoArquivo(resposta));
                avisar(link.getAttribute('data-voto-sucesso'), false);
            });
        }).catch(function () {
            avisar(link.getAttribute('data-voto-erro'), true);
        });
    }

    document.addEventListener('click', function (ev) {
        var link = ev.target.closest('[data-voto-relatorio]');
        if (!link) { return; }
        var janela = link.closest('[data-voto-janela-raiz]');
        if (link.hasAttribute('data-voto-baixar')) {
            ev.preventDefault();
            baixar(link);
        } else {
            avisar(link.getAttribute('data-voto-sucesso'), false);
        }
        if (janela) {
            var fechar = janela.querySelector('[data-voto-janela-fechar]');
            if (fechar) { fechar.click(); }
        }
    });
})();
