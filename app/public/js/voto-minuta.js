/* BlueJus Voto: "Minuta de ata" e "Papel timbrado para ata" (desenho 18). O .docx do timbre escolhido no rótulo do
   cabeçalho [data-voto-timbre] fica no navegador (localStorage 'bj-voto-timbrado', como o desenho; se não couber, só na
   memória desta página) e vai junto, no campo `timbre`, em cada minuta pedida por [data-voto-minuta]. O formulário baixa
   o .docx ou o PDF por fetch e mostra o toast do desenho; se o servidor recusar (sem votação apurada, modelo inválido) ele
   devolve ao painel com o aviso e o navegador segue o redirecionamento. Modelo acima de 2,5 MB é recusado aqui, antes
   de guardar. Sem JS o formulário baixa igual, sem timbre. */
(function () {
    'use strict';

    var CHAVE = 'bj-voto-timbrado';
    var LIMITE_DO_MODELO = 2500000;
    var TIPO_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    var naMemoria = null;

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

    function paraBase64(bytes) {
        var s = '';
        for (var i = 0; i < bytes.length; i += 32768) {
            s += String.fromCharCode.apply(null, bytes.subarray(i, i + 32768));
        }
        return window.btoa(s);
    }

    function deBase64(b64) {
        var s = window.atob(b64);
        var bytes = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) { bytes[i] = s.charCodeAt(i); }
        return bytes;
    }

    /** O timbre guardado ({nome, bytes}) na memória desta página ou no navegador; null se não há. */
    function timbre() {
        if (naMemoria) { return naMemoria; }
        try {
            var guardado = JSON.parse(window.localStorage.getItem(CHAVE) || 'null');
            if (guardado && guardado.b64) {
                naMemoria = { nome: guardado.nome, bytes: deBase64(guardado.b64) };
            }
        } catch (e) { naMemoria = null; }
        return naMemoria;
    }

    function rotular(campo) {
        var texto = campo.parentNode.querySelector('[data-voto-timbre-texto]');
        var atual = timbre();
        texto.textContent = atual
            ? campo.getAttribute('data-voto-timbre-modelo').replace('__NOME__', atual.nome)
            : campo.getAttribute('data-voto-timbre-padrao');
    }

    function escolher(campo) {
        var arquivo = campo.files[0];
        campo.value = '';
        if (!arquivo) { return; }
        if (arquivo.size > LIMITE_DO_MODELO) {
            avisar(campo.getAttribute('data-voto-grande'), true);
            return;
        }
        arquivo.arrayBuffer().then(function (buffer) {
            var bytes = new Uint8Array(buffer);
            naMemoria = { nome: arquivo.name, bytes: bytes };
            var guardou = true;
            try {
                window.localStorage.setItem(CHAVE, JSON.stringify({ nome: arquivo.name, b64: paraBase64(bytes) }));
            } catch (e) { guardou = false; }
            rotular(campo);
            avisar(campo.getAttribute(guardou ? 'data-voto-salvo' : 'data-voto-so-pagina'), !guardou);
        });
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
        var modelo = comModelo ? timbre() : null;
        if (modelo) { dados.append('timbre', new File([modelo.bytes], modelo.nome, { type: TIPO_DOCX })); }
        fetch(acao, { method: 'POST', body: dados, credentials: 'same-origin' }).then(function (resposta) {
            var tipo = resposta.headers.get('Content-Type') || '';
            if (resposta.ok && resposta.redirected && tipo.indexOf('text/html') === 0) {
                window.location.href = resposta.url;
                return undefined;
            }
            if (!resposta.ok || tipo.indexOf('text/html') === 0) { throw new Error('sem arquivo'); }
            return resposta.blob().then(function (blob) {
                salvar(blob, nomeDoArquivo(resposta));
                avisar(form.getAttribute(modelo ? 'data-voto-sucesso-timbrado' : 'data-voto-sucesso'), false);
            });
        }).catch(function () {
            avisar(form.getAttribute('data-voto-erro'), true);
        });
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target.closest ? ev.target.closest('[data-voto-minuta]') : null;
        if (!form || !window.fetch || !window.FormData || !window.File) { return; }
        ev.preventDefault();
        var botao = ev.submitter;
        var acao = botao && botao.getAttribute('formaction') ? botao.getAttribute('formaction') : form.getAttribute('action');
        enviar(form, acao, acao === form.getAttribute('action'));
    });

    document.addEventListener('change', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-voto-timbre]')) { escolher(ev.target); }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var campo = document.querySelector('[data-voto-timbre]');
        if (campo) { rotular(campo); }
    });
}());
