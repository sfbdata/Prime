/* BlueJus Voto: assembleia de condomínio, telas do gestor (spec docs/specs/voto-assembleia-condominio.md).
   - toast do desenho (L281): some em 3600 ms; "Link copiado." e "Mensagem copiada." usam o mesmo elemento;
   - cronômetro da mesa ("Encerra em mm:ss"): conta no navegador e recarrega ao zerar (o servidor encerra a pauta
     na primeira leitura depois do prazo);
   - "Copiar link" e "Copiar mensagem";
   - "Candidatura" acrescenta uma linha de opção; a lixeira da linha a tira; "Outro cargo ou pauta" mostra o
     formulário da pauta nova;
   - pautas em rascunho (etapa 4 do desenho): ficam em edição juntas e nada se perde entre elas. O "Nº" só aceita
     algarismo (digitado ou colado); o "Salvar" de uma pauta grava por fetch, sem recarregar (a nova recarrega só
     depois de gravada); antes de uma ação que recarrega ou sai da tela (atalho, remover, rodapé, abas), grava as
     pautas alteradas e, se uma falhar, mostra o erro acima do rodapé e fica na tela com tudo o que foi digitado
     (repetir a ação segue sem a pauta recusada); fora da tela, o aviso do navegador;
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

    /* Pautas em rascunho. `telaDePautas` é a caixa da etapa 4 ([data-voto-pautas]); fora dela nada disto roda.
       `gravando`: há gravação por fetch em curso. `saindo`: a página já vai ser trocada (barra o segundo envio e o
       aviso do navegador; expira em TEMPO_DA_SAIDA se a carga for interrompida). `erroDe`: a pauta do erro fixo. */
    var telaDePautas = null;
    var gravando = false;
    var saindo = false;
    var erroDe = null;
    var TEMPO_DA_SAIDA = 8000;

    function formsDePauta() {
        return Array.prototype.slice.call(document.querySelectorAll('form[data-voto-pauta-form]'));
    }

    /* O que está na pauta (título, descrição e as linhas de opção, na ordem), sem o token. Com `doServidor`, usa o
       valor que veio na página (defaultValue), não o que o navegador possa ter restaurado no F5. */
    function retrato(form, doServidor) {
        var partes = [];
        Array.prototype.forEach.call(form.elements, function (campo) {
            if (campo.name && campo.name !== '_token' && (campo.tagName === 'INPUT' || campo.tagName === 'TEXTAREA')) {
                partes.push([campo.name, doServidor ? campo.defaultValue : campo.value]);
            }
        });
        return JSON.stringify(partes);
    }

    /* Diferente do que está gravado. A pauta nova escondida nunca conta. */
    function alterada(form) {
        return !form.hidden && retrato(form, false) !== form.getAttribute('data-voto-retrato');
    }

    /* Alterada e ainda não recusada do jeito que está: a pauta que o servidor recusou na validação é avisada uma vez;
       repetir a ação segue sem ela (a pessoa não fica presa na tela). */
    function pendente(form) {
        return alterada(form) && retrato(form, false) !== form.getAttribute('data-voto-recusada');
    }

    function pendentes(exceto) {
        return formsDePauta().filter(function (form) {
            return exceto.indexOf(form) === -1 && pendente(form);
        });
    }

    function texto(atributo) {
        return telaDePautas ? telaDePautas.getAttribute(atributo) || '' : '';
    }

    /* O erro da etapa (edErro do desenho, L226): texto fixo acima do rodapé. */
    function mostrarErro(mensagem, form) {
        erroDe = mensagem === '' ? null : form || null;
        var caixa = telaDePautas ? telaDePautas.querySelector('[data-voto-erro]') : null;
        if (!caixa) {
            avisar(mensagem, true);
            return;
        }
        caixa.textContent = mensagem;
        caixa.hidden = mensagem === '';
    }

    /* Grava uma pauta por fetch. Resolve com {ok, recusada, mensagem, enviado}: a mensagem do servidor, a do login
       vencido (401 com `erro`) ou, sem resposta legível, o texto de falha. `recusada` só na recusa de validação
       (422); rede, sessão e erro do servidor não descartam a pauta. Só marca como gravado o que foi enviado (o que
       for digitado durante o envio continua pendente). */
    function gravar(form) {
        var enviado = retrato(form, false);
        gravando = true;
        return window.fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (resposta) {
            return resposta.json().then(function (dados) { return { status: resposta.status, dados: dados }; });
        }).then(function (resposta) {
            var dados = resposta.dados;
            var mensagem = dados && typeof dados.mensagem === 'string' ? dados.mensagem : (dados && typeof dados.erro === 'string' ? dados.erro : null);
            if (mensagem === null) {
                throw new Error('resposta sem mensagem');
            }
            var ok = dados.ok === true;
            if (ok) {
                form.setAttribute('data-voto-retrato', enviado);
            }
            return { ok: ok, recusada: !ok && resposta.status === 422, mensagem: mensagem, enviado: enviado };
        }).catch(function () {
            return { ok: false, recusada: false, mensagem: texto('data-voto-falha'), enviado: enviado };
        }).then(function (resultado) {
            gravando = false;
            return resultado;
        });
    }

    /* Grava, uma de cada vez, as pautas pendentes (menos as de `exceto`) e, por último, as de `tambem`. As que já
       existem vão antes e a nova por último: se uma falhar, a nova não é criada. Resolve com null ou com a pauta
       que falhou e a mensagem. */
    function gravarPendentes(exceto, tambem) {
        var lista = pendentes(exceto.concat(tambem)).sort(function (a, b) {
            return (a.getAttribute('data-voto-pauta-form') === 'nova' ? 1 : 0) - (b.getAttribute('data-voto-pauta-form') === 'nova' ? 1 : 0);
        }).concat(tambem);
        return lista.reduce(function (cadeia, form) {
            return cadeia.then(function (falha) {
                if (falha !== null) {
                    return falha;
                }
                return gravar(form).then(function (dados) { return dados.ok ? null : { form: form, recusada: dados.recusada, mensagem: dados.mensagem, enviado: dados.enviado }; });
            });
        }, Promise.resolve(null));
    }

    /* A pauta que falhou: o erro fixo e o foco no primeiro campo dela. Recusada na validação, ela fica marcada do
       jeito que está e o erro convida a corrigir ou repetir (repetir segue sem ela); falha de rede ou de sessão só
       avisa, e repetir tenta gravar de novo. */
    function mostrarFalha(falha) {
        if (falha.recusada) {
            falha.form.setAttribute('data-voto-recusada', falha.enviado);
            mostrarErro(falha.mensagem + ' ' + texto('data-voto-repetir'), falha.form);
        } else {
            mostrarErro(falha.mensagem, falha.form);
        }
        var campo = falha.form.querySelector('input:not([type="hidden"]), textarea');
        if (campo) {
            campo.focus();
        }
    }

    function marcarSaida() {
        saindo = true;
        window.setTimeout(function () { saindo = false; }, TEMPO_DA_SAIDA);
    }

    function sair(acao) {
        marcarSaida();
        acao();
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
        marcarSaida();
        form.submit();
    }

    /* "Salvar" de uma pauta que já existe: grava sem recarregar e mostra o toast. */
    function salvarSemRecarregar(form, botao) {
        if (botao) {
            botao.disabled = true;
        }
        gravar(form).then(function (dados) {
            if (botao) {
                botao.disabled = false;
            }
            if (dados.ok && erroDe === form) {
                mostrarErro('');
            }
            avisar(dados.mensagem, !dados.ok);
        });
    }

    /* "Salvar" da pauta nova: grava as outras pendentes e a nova por fetch; com tudo certo, recarrega para a nova
       aparecer com as outras (o toast vai junto); com erro, nada do que foi digitado se perde. */
    function incluirPautaNova(form, botao) {
        if (botao) {
            botao.disabled = true;
        }
        gravarPendentes([], [form]).then(function (falha) {
            if (botao) {
                botao.disabled = false;
            }
            if (falha !== null) {
                if (falha.form === form) {
                    avisar(falha.mensagem, true);
                } else {
                    mostrarFalha(falha);
                }
                return;
            }
            guardarAviso(texto('data-voto-incluida'));
            sair(function () { window.location.assign(form.action); });
        });
    }

    /* O toast que aparece depois de recarregar (a resposta do fetch não passa pela sessão). */
    function guardarAviso(mensagem) {
        try {
            window.sessionStorage.setItem('voto-aviso', mensagem);
        } catch (e) {
            // sem armazenamento: segue sem o toast
        }
    }

    function avisoGuardado() {
        try {
            var mensagem = window.sessionStorage.getItem('voto-aviso');
            window.sessionStorage.removeItem('voto-aviso');
            return mensagem;
        } catch (e) {
            return null;
        }
    }

    function soAlgarismos(campo, valor) {
        var limpo = valor.replace(/\D/g, '');
        return campo.maxLength > 0 ? limpo.slice(0, campo.maxLength) : limpo;
    }

    document.addEventListener('input', function (evento) {
        var campo = evento.target;
        if (campo && campo.hasAttribute && campo.hasAttribute('data-voto-numero')) {
            var limpo = soAlgarismos(campo, campo.value);
            if (limpo !== campo.value) {
                campo.value = limpo;
            }
        }
    });

    /* Colar no Nº: o maxlength cortaria o texto antes do filtro ("a1" viraria "a" e depois nada). */
    document.addEventListener('paste', function (evento) {
        var campo = evento.target;
        if (!campo || !campo.hasAttribute || !campo.hasAttribute('data-voto-numero')) {
            return;
        }
        var dados = evento.clipboardData || window.clipboardData;
        if (!dados) {
            return;
        }
        evento.preventDefault();
        var limpo = soAlgarismos(campo, dados.getData('text') || '');
        if (limpo !== '') {
            campo.value = limpo;
        }
    });

    document.addEventListener('submit', function (evento) {
        var form = evento.target;
        if (!telaDePautas || !form.closest || !form.closest('.voto-tela')) {
            return;
        }
        if (form.hasAttribute('data-voto-liberado')) {
            form.removeAttribute('data-voto-liberado');
            marcarSaida();
            return;
        }
        if (gravando || saindo) {
            evento.preventDefault();
            return;
        }
        // Sem `submitter` (navegador antigo), o botão com nome do form (o do atalho) leva o valor.
        var botao = evento.submitter || form.querySelector('button[type="submit"][name]');
        var tipo = form.getAttribute('data-voto-pauta-form');
        if (tipo === 'existente' || tipo === 'nova') {
            evento.preventDefault();
            if (tipo === 'existente') {
                salvarSemRecarregar(form, botao);
            } else {
                incluirPautaNova(form, botao);
            }
            return;
        }
        // Atalho e remover: grava antes as pautas pendentes (a que vai ser removida, não).
        var bloco = form.closest('.voto-pauta-bloco');
        var exceto = [form].concat(bloco ? Array.prototype.slice.call(bloco.querySelectorAll('form[data-voto-pauta-form]')) : []);
        if (pendentes(exceto).length === 0) {
            marcarSaida();
            return;
        }
        evento.preventDefault();
        mostrarErro('');
        gravarPendentes(exceto, []).then(function (falha) {
            if (falha !== null) {
                mostrarFalha(falha);
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
        if (gravando || saindo) {
            evento.preventDefault();
            return;
        }
        if (pendentes([]).length === 0) {
            marcarSaida();
            return;
        }
        evento.preventDefault();
        mostrarErro('');
        gravarPendentes([], []).then(function (falha) {
            if (falha !== null) {
                mostrarFalha(falha);
                return;
            }
            sair(function () { window.location.href = link.href; });
        });
    });

    /* Fora da tela (menu lateral, topo, F5, Voltar do navegador): o aviso do navegador, como no editor do blog. Conta
       também a pauta recusada: seguir sem ela vale só para a ação repetida na tela. */
    window.addEventListener('beforeunload', function (evento) {
        if (!telaDePautas || saindo || formsDePauta().filter(alterada).length === 0) {
            return;
        }
        evento.preventDefault();
        evento.returnValue = '';
    });

    /* Voltar pelo histórico com a página guardada (bfcache): a tela volta a aceitar ações. */
    window.addEventListener('pageshow', function () {
        saindo = false;
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
            formsDePauta().forEach(function (form) { form.setAttribute('data-voto-retrato', retrato(form, true)); });
            var aviso = avisoGuardado();
            if (aviso) {
                avisar(aviso, false);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
