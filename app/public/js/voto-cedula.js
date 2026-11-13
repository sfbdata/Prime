/*
 * Cédula da assembleia de condomínio (/votar/{token}). Vanilla, sem dependência, sem Bootstrap.
 *
 * - O voto acontece na cabine (js/voto-cabine.js).
 * - "Encerra em mm:ss." conta para trás a partir dos segundos que o servidor mandou (não usa o relógio do aparelho);
 *   ao zerar, recarrega a página, que mostra a pauta encerrada.
 * - Com pauta à espera da mesa (body[data-vc-recarregar]), recarrega a cada N segundos, nunca com a cabine aberta.
 * - O toast some em 3600 ms, como no desenho.
 *
 * Nada aqui escreve HTML vindo de fora: só textos que o servidor já pôs em atributos data-*.
 */
(function () {
    'use strict';

    // Compartilhado pelo comprovante da tela FIM e pelo cartão de uma pauta já votada.
    function baixarComprovante(dados) {
        var lista = dados.lista;
        var cv = document.createElement('canvas');
        cv.width = 900;
        cv.height = 230 + lista.length * 74;
        var g = cv.getContext('2d');
        g.fillStyle = '#ffffff';
        g.fillRect(0, 0, cv.width, cv.height);
        g.fillStyle = '#0a6fa0';
        g.fillRect(0, 0, cv.width, 10);
        g.fillStyle = '#0b3550';
        g.font = 'bold 34px Arial, Helvetica, sans-serif';
        g.fillText(dados.titulo, 40, 70);
        g.fillStyle = '#12242f';
        g.font = '24px Arial, Helvetica, sans-serif';
        g.fillText(dados.assembleia, 40, 112);
        g.fillStyle = '#34505f';
        g.font = '20px Arial, Helvetica, sans-serif';
        g.fillText(dados.data || new Date().toLocaleString('pt-BR'), 40, 146);
        lista.forEach(function (x, i) {
            var y = 200 + i * 74;
            g.fillStyle = '#12242f';
            g.font = '22px Arial, Helvetica, sans-serif';
            g.fillText(x.pauta, 40, y);
            g.fillStyle = '#1e7b45';
            g.font = 'bold 30px Consolas, Menlo, monospace';
            g.fillText(x.codigo, 40, y + 36);
        });
        g.fillStyle = '#34505f';
        g.font = '17px Arial, Helvetica, sans-serif';
        g.fillText(dados.rodape, 40, cv.height - 28);
        cv.toBlob(function (blob) {
            if (!blob) {
                return;
            }
            var u = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = u;
            a.download = dados.arquivo || 'comprovante.png';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(u); }, 3000);
        }, 'image/png');
    }

    window.VotoComprovante = {baixar: baixarComprovante};

    document.querySelectorAll('[data-vc-comprovante-imagem]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            baixarComprovante(JSON.parse(botao.getAttribute('data-vc-comprovante-imagem')));
        });
    });

    document.querySelectorAll('[data-vc-comprovante-email]').forEach(function (form) {
        form.addEventListener('submit', function (evento) {
            evento.preventDefault();
            if (form.hasAttribute('data-vc-enviando')) { return; }
            var botao = form.querySelector('button[type="submit"]');
            var resposta = form.parentElement.querySelector('[data-vc-comprovante-resposta]');
            var pedido = new FormData(form);
            form.setAttribute('data-vc-enviando', '');
            botao.disabled = true;
            form.setAttribute('aria-busy', 'true');
            resposta.textContent = '';
            fetch(form.action, {method: 'POST', body: pedido, credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) {
                    if (r.redirected) { throw new Error(); }
                    return r.json();
                })
                .then(function (j) {
                    resposta.className = 'vcComprovanteResposta ' + (j.ok ? 'vcMsgVerde' : 'vcMsgVermelho');
                    resposta.textContent = j.mensagem || form.getAttribute('data-vc-erro');
                })
                .catch(function () {
                    resposta.className = 'vcComprovanteResposta vcMsgVermelho';
                    resposta.textContent = form.getAttribute('data-vc-erro');
                })
                .finally(function () {
                    form.removeAttribute('data-vc-enviando');
                    form.removeAttribute('aria-busy');
                    botao.disabled = false;
                });
        });
    });

    function doisDigitos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    var inicio = Date.now();
    var prazos = Array.prototype.slice.call(document.querySelectorAll('[data-vc-prazo]'));
    var recarregando = false;
    var zerou = false;

    function recarregar() {
        if (recarregando) {
            return;
        }
        if (document.body.classList.contains('uAberta') || document.querySelector('[data-vc-enviando]') || document.activeElement.closest('[data-vc-comprovante-email]')) {
            setTimeout(recarregar, 2000);
            return;
        }
        recarregando = true;
        window.location.reload();
    }

    function contar() {
        var passados = Math.floor((Date.now() - inicio) / 1000);
        prazos.forEach(function (el) {
            var restam = Math.max(0, parseInt(el.getAttribute('data-vc-prazo'), 10) - passados);
            var texto = el.querySelector('[data-vc-prazo-texto]');
            var modelo = el.getAttribute('data-vc-modelo') || '';
            if (texto) {
                texto.textContent = modelo.replace('{t}', doisDigitos(Math.floor(restam / 60)) + ':' + doisDigitos(restam % 60));
            }
            if (restam === 0 && !zerou) {
                zerou = true;
                setTimeout(recarregar, 1000);
            }
        });
    }

    if (prazos.length > 0) {
        setInterval(contar, 1000);
    }

    var intervalo = parseInt(document.body.getAttribute('data-vc-recarregar') || '', 10);
    if (intervalo > 0) {
        setTimeout(recarregar, intervalo * 1000);
    }

    document.querySelectorAll('[data-vc-toast]').forEach(function (toast) {
        setTimeout(function () {
            toast.hidden = true;
        }, 3600);
    });
})();
