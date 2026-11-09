/* BlueJus Voto: assembleia de condomínio, telas do gestor (spec docs/specs/voto-assembleia-condominio.md).
   - toast do desenho (L281): some em 3600 ms; "Link copiado." e "Mensagem copiada." usam o mesmo elemento;
   - cronômetro da mesa ("Encerra em mm:ss"): conta no navegador e recarrega ao zerar (o servidor encerra a pauta
     na primeira leitura depois do prazo);
   - "Copiar link" e "Copiar mensagem";
   - "Candidatura" acrescenta uma linha de opção; a lixeira da linha a tira; "Outro cargo ou pauta" mostra o
     formulário da pauta nova;
   - "Assistir" abre a página própria num pop-up de 620x560 (se o navegador bloquear, segue o link em outra aba);
   - "Imprimir" na ata. */
(function () {
    'use strict';

    var TEMPO_DO_TOAST = 3600;

    function caixaDeToasts() {
        var caixa = document.querySelector('[data-voto-toasts]');
        if (!caixa) {
            caixa = document.createElement('div');
            caixa.className = 'voto-toasts';
            caixa.setAttribute('data-voto-toasts', '');
            document.body.appendChild(caixa);
        }
        return caixa;
    }

    function sumirDepois(toast) {
        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, TEMPO_DO_TOAST);
    }

    function avisar(texto, erro) {
        var toast = document.createElement('div');
        toast.className = 'voto-toast' + (erro ? ' voto-toast-erro' : '');
        toast.setAttribute('role', 'status');
        toast.textContent = texto;
        caixaDeToasts().appendChild(toast);
        sumirDepois(toast);
    }

    function doisDigitos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function iniciarCronometro(el) {
        var restantes = parseInt(el.getAttribute('data-voto-prazo'), 10);
        var modelo = el.getAttribute('data-modelo') || '{tempo}';
        if (isNaN(restantes) || restantes <= 0) {
            return;
        }
        var fim = Date.now() + restantes * 1000;
        var relogio = window.setInterval(function () {
            var falta = Math.max(0, Math.round((fim - Date.now()) / 1000));
            el.textContent = modelo.replace('{tempo}', doisDigitos(Math.floor(falta / 60)) + ':' + doisDigitos(falta % 60));
            if (falta === 0) {
                window.clearInterval(relogio);
                window.setTimeout(function () { window.location.reload(); }, 1500);
            }
        }, 1000);
    }

    function copiar(texto, aviso) {
        var feito = function () { avisar(aviso, false); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(feito, function () { copiarPelaArea(texto) && feito(); });
            return;
        }
        if (copiarPelaArea(texto)) {
            feito();
        }
    }

    function copiarPelaArea(texto) {
        var area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(area);
        return ok;
    }

    function novaOpcao(botao) {
        var form = botao.closest('form');
        var lista = form ? form.querySelector('[data-voto-opcoes]') : null;
        var modelo = lista ? lista.querySelector('[data-voto-opcao]') : null;
        if (!modelo) {
            return;
        }
        var linha = modelo.cloneNode(true);
        Array.prototype.forEach.call(linha.querySelectorAll('input'), function (campo) { campo.value = ''; });
        lista.appendChild(linha);
        linha.querySelector('input').focus();
    }

    function removerOpcao(botao) {
        var linha = botao.closest('[data-voto-opcao]');
        var lista = linha ? linha.parentNode : null;
        if (!linha || !lista) {
            return;
        }
        if (lista.querySelectorAll('[data-voto-opcao]').length > 1) {
            lista.removeChild(linha);
            return;
        }
        Array.prototype.forEach.call(linha.querySelectorAll('input'), function (campo) { campo.value = ''; });
    }

    document.addEventListener('click', function (evento) {
        var alvo = evento.target.closest('[data-voto-copiar], [data-voto-opcao-nova], [data-voto-opcao-remover], [data-voto-mostrar], [data-voto-assistir], [data-voto-imprimir]');
        if (!alvo) {
            return;
        }
        if (alvo.hasAttribute('data-voto-copiar')) {
            copiar(alvo.getAttribute('data-voto-copiar'), alvo.getAttribute('data-voto-copiado') || '');
        } else if (alvo.hasAttribute('data-voto-opcao-nova')) {
            novaOpcao(alvo);
        } else if (alvo.hasAttribute('data-voto-opcao-remover')) {
            removerOpcao(alvo);
        } else if (alvo.hasAttribute('data-voto-mostrar')) {
            var caixa = document.getElementById(alvo.getAttribute('data-voto-mostrar'));
            if (caixa) {
                caixa.hidden = false;
                alvo.hidden = true;
                var primeiro = caixa.querySelector('input:not([type="hidden"])');
                if (primeiro) {
                    primeiro.focus();
                }
            }
        } else if (alvo.hasAttribute('data-voto-assistir')) {
            var janela = window.open(alvo.href, 'voto-assistir-' + (alvo.closest('[data-pauta]') || alvo).getAttribute('data-pauta'), 'popup=yes,width=620,height=560,resizable=yes,scrollbars=yes');
            if (janela) {
                evento.preventDefault();
            }
        } else if (alvo.hasAttribute('data-voto-imprimir')) {
            window.print();
        }
    });

    function iniciar() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-voto-toasts] .voto-toast'), sumirDepois);
        Array.prototype.forEach.call(document.querySelectorAll('[data-voto-prazo]'), iniciarCronometro);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
