/* BlueJus Voto: assembleia de condomínio, telas do gestor (spec docs/specs/voto-assembleia-condominio.md).
   - toast do desenho (L281): some em 3600 ms; "Link copiado." e "Mensagem copiada." usam o mesmo elemento;
   - cronômetro da mesa ("Encerra em mm:ss"): conta no navegador e recarrega ao zerar (o servidor encerra a pauta
     na primeira leitura depois do prazo);
   - "Copiar link" e "Copiar mensagem";
   - "Candidatura" acrescenta uma linha de opção; a lixeira da linha a tira; "Outro cargo ou pauta" mostra o
     formulário da pauta nova;
   - pautas em rascunho (etapa 4 do desenho): ficam em edição juntas e nada se perde entre elas. O "Nº" só aceita
     algarismo; o "Salvar" de uma pauta que já existe grava por fetch, sem recarregar; antes de qualquer ação que
     recarrega ou sai da tela (atalho, pauta nova, remover, rodapé, abas), grava as pautas alteradas e, se uma
     falhar, mostra o erro e fica na tela com tudo o que foi digitado;
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

    /* Pautas em rascunho. `telaDePautas` é a caixa da etapa 4 ([data-voto-pautas]); fora dela nada disto roda. */
    var telaDePautas = null;
    var gravando = false;

    function formsDePauta() {
        return Array.prototype.slice.call(document.querySelectorAll('form[data-voto-pauta-form]'));
    }

    /* O que a pessoa digitou na pauta (título, descrição e as linhas de opção, na ordem), sem o token. */
    function retrato(form) {
        var partes = [];
        Array.prototype.forEach.call(form.elements, function (campo) {
            if (campo.name && campo.name !== '_token' && (campo.tagName === 'INPUT' || campo.tagName === 'TEXTAREA')) {
                partes.push([campo.name, campo.value]);
            }
        });
        return JSON.stringify(partes);
    }

    function alterada(form) {
        return retrato(form) !== form.getAttribute('data-voto-retrato');
    }

    function pendentes(exceto) {
        return formsDePauta().filter(function (form) {
            return exceto.indexOf(form) === -1 && alterada(form);
        });
    }

    function textoDeFalha() {
        return telaDePautas ? telaDePautas.getAttribute('data-voto-falha') || '' : '';
    }

    /* Grava uma pauta por fetch. Resolve com {ok, mensagem}; falha de rede ou resposta estranha vira o texto de
       falha. Só marca a pauta como gravada com o que foi enviado (o que for digitado durante o envio continua
       pendente). */
    function gravar(form) {
        var enviado = retrato(form);
        return window.fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (resposta) {
            return resposta.json();
        }).then(function (dados) {
            if (!dados || typeof dados.mensagem !== 'string') {
                throw new Error('resposta sem mensagem');
            }
            if (dados.ok) {
                form.setAttribute('data-voto-retrato', enviado);
            }
            return { ok: !!dados.ok, mensagem: dados.mensagem };
        }).catch(function () {
            return { ok: false, mensagem: textoDeFalha() };
        });
    }

    /* Grava, uma de cada vez, as pautas alteradas (menos as de `exceto`). As que já existem vão antes e a nova por
       último: se uma falhar, a nova não é criada e o "Salvar" dela não a duplica. Resolve com a mensagem do erro
       ou null. */
    function gravarPendentes(exceto) {
        var lista = pendentes(exceto).sort(function (a, b) {
            return (a.getAttribute('data-voto-pauta-form') === 'nova' ? 1 : 0) - (b.getAttribute('data-voto-pauta-form') === 'nova' ? 1 : 0);
        });
        gravando = true;
        return lista.reduce(function (cadeia, form) {
            return cadeia.then(function (falha) {
                if (falha !== null) {
                    return falha;
                }
                return gravar(form).then(function (dados) { return dados.ok ? null : dados.mensagem; });
            });
        }, Promise.resolve(null)).then(function (falha) {
            gravando = false;
            return falha;
        });
    }

    function enviarDeNovo(form, botao) {
        form.setAttribute('data-voto-liberado', '');
        if (typeof form.requestSubmit === 'function') {
            if (botao) {
                form.requestSubmit(botao);
            } else {
                form.requestSubmit();
            }
            return;
        }
        if (botao && botao.name) {
            var campo = document.createElement('input');
            campo.type = 'hidden';
            campo.name = botao.name;
            campo.value = botao.value;
            form.appendChild(campo);
        }
        form.submit();
    }

    function salvarSemRecarregar(form, botao) {
        if (botao) {
            botao.disabled = true;
        }
        gravar(form).then(function (dados) {
            if (botao) {
                botao.disabled = false;
            }
            avisar(dados.mensagem, !dados.ok);
        });
    }

    document.addEventListener('input', function (evento) {
        var campo = evento.target;
        if (campo && campo.hasAttribute && campo.hasAttribute('data-voto-numero')) {
            var limpo = campo.value.replace(/\D/g, '');
            if (limpo !== campo.value) {
                campo.value = limpo;
            }
        }
    });

    document.addEventListener('submit', function (evento) {
        var form = evento.target;
        if (!telaDePautas || !form.closest || !form.closest('.voto-tela') || form.hasAttribute('data-voto-liberado')) {
            return;
        }
        if (gravando) {
            evento.preventDefault();
            return;
        }
        var botao = evento.submitter || null;
        if (form.getAttribute('data-voto-pauta-form') === 'existente') {
            evento.preventDefault();
            salvarSemRecarregar(form, botao);
            return;
        }
        // A pauta que vai ser removida não é gravada antes.
        var bloco = form.closest('.voto-pauta-bloco');
        var exceto = [form].concat(bloco ? Array.prototype.slice.call(bloco.querySelectorAll('form[data-voto-pauta-form]')) : []);
        if (pendentes(exceto).length === 0) {
            return;
        }
        evento.preventDefault();
        gravarPendentes(exceto).then(function (falha) {
            if (falha !== null) {
                avisar(falha, true);
                return;
            }
            enviarDeNovo(form, botao);
        });
    });

    /* Rodapé ("Salvar e sair", "Voltar", "Continuar"), abas e sub-abas: no desenho, sair da etapa grava as pautas. */
    document.addEventListener('click', function (evento) {
        var link = telaDePautas ? evento.target.closest('.voto-tela a[href]:not([data-voto-assistir])') : null;
        if (!link || evento.defaultPrevented || evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
            return;
        }
        if ((link.target && link.target !== '_self') || link.hasAttribute('download') || (link.getAttribute('href') || '').charAt(0) === '#') {
            return;
        }
        if (gravando) {
            evento.preventDefault();
            return;
        }
        if (pendentes([]).length === 0) {
            return;
        }
        evento.preventDefault();
        gravarPendentes([]).then(function (falha) {
            if (falha !== null) {
                avisar(falha, true);
                return;
            }
            window.location.href = link.href;
        });
    });

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
        telaDePautas = document.querySelector('[data-voto-pautas]');
        if (telaDePautas) {
            formsDePauta().forEach(function (form) { form.setAttribute('data-voto-retrato', retrato(form)); });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
