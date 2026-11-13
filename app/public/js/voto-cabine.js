/*
 * Cabine de votação (urna virtual) da cédula da assembleia (/votar/{token}). Vanilla, sem dependência.
 * Desenho 18 (docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html, L257-280 e o JS de L331-345:
 * entrar, digito, branco, corrige, confirma, grava, piscaLeds, somT). Contrato e desvios: docs/specs/voto-cabine.md.
 *
 * - Cada pauta aberta e ainda não votada pela unidade é um "cargo" da cabine: o eleitor digita o número da opção;
 *   BRANCO é a ABSTENÇÃO (o desenho chama assim quando a votação é de pauta); CONFIRMA passa à próxima pauta.
 * - Ao confirmar a última, os votos seguem um a um para o mesmo POST da cédula (`/votar/{token}/pauta/{id}`, CSRF
 *   da página), pedindo JSON. Nada é gravado antes disso; em "Simular votação" nada é enviado.
 * - Todo texto vem do servidor (atributo data-vc-cabine-dados) e entra por textContent: nada escreve HTML de fora.
 */
(function () {
    'use strict';

    if (window.__vcCabine === true) {
        return; // o guarda da cédula carregou o arquivo de novo e a cabine já tinha subido
    }
    var raiz = document.querySelector('[data-vc-cabine]');
    var dadosEl = document.querySelector('[data-vc-cabine-dados]');
    if (!raiz || !dadosEl) {
        return;
    }

    // Avisa o guarda da cédula que a cabine subiu: some a reserva e a próxima falha volta a ser tratada.
    window.__vcCabine = true;
    document.querySelectorAll('[data-vc-reserva]').forEach(function (e) { e.hidden = true; });
    document.querySelectorAll('[data-vc-acoes]').forEach(function (e) { e.hidden = false; });
    try { sessionStorage.removeItem('vc_recarregou'); } catch (e) { /* sem armazenamento: o guarda só mostra a reserva */ }

    var D;
    try {
        D = JSON.parse(dadosEl.getAttribute('data-vc-cabine-dados') || '{}');
    } catch (e) {
        return;
    }
    var T = D.textos || {};
    var tela = raiz.querySelector('[data-u-tela]');
    var faixa = raiz.querySelector('[data-u-faixa]');
    var fala = raiz.querySelector('[data-u-fala]');
    var botaoSom = raiz.querySelector('[data-u-som]');
    var leds = Array.prototype.slice.call(raiz.querySelectorAll('.uFen i'));
    var botoes = {
        branco: raiz.querySelector('[data-u-branco]'),
        corrige: raiz.querySelector('[data-u-corrige]'),
        confirma: raiz.querySelector('[data-u-confirma]')
    };

    var som = true;
    var ctxAudio = null;
    var ultimoFoco = null;
    var c = null; // estado da cabine aberta; null = fechada

    function texto(chave, trocas) {
        var t = T[chave] || '';
        Object.keys(trocas || {}).forEach(function (k) {
            t = t.split('{' + k + '}').join(trocas[k]);
        });
        return t;
    }

    function el(tag, classe, conteudo) {
        var e = document.createElement(tag);
        if (classe) {
            e.className = classe;
        }
        if (conteudo !== undefined && conteudo !== null) {
            e.textContent = conteudo;
        }
        return e;
    }

    function icone(nome) {
        var i = el('i', 'bi ' + nome);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function botao(rotulo, nomeIcone, classe, aoClicar) {
        var b = el('button', 'vB' + (classe ? ' ' + classe : ''));
        b.type = 'button';
        if (nomeIcone) {
            b.appendChild(icone(nomeIcone));
        }
        b.appendChild(document.createTextNode(rotulo));
        b.addEventListener('click', aoClicar);
        return b;
    }

    function reduzMovimento() {
        try {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        } catch (e) {
            return false;
        }
    }

    /* ---- Som (somT do desenho): sequência de [frequência, duração] em ondas senoidais ---- */
    function tocar(sequencia, ganho) {
        if (!som) {
            return;
        }
        try {
            ctxAudio = ctxAudio || new (window.AudioContext || window.webkitAudioContext)();
            var ac = ctxAudio;
            if (ac.state === 'suspended') {
                ac.resume();
            }
            var t = ac.currentTime + 0.01;
            sequencia.forEach(function (par) {
                var o = ac.createOscillator();
                var a = ac.createGain();
                o.type = 'sine';
                o.frequency.value = par[0];
                a.gain.setValueAtTime(0.0001, t);
                a.gain.exponentialRampToValueAtTime(ganho || 0.07, t + 0.012);
                a.gain.exponentialRampToValueAtTime(0.0001, t + par[1]);
                o.connect(a);
                a.connect(ac.destination);
                o.start(t);
                o.stop(t + par[1] + 0.03);
                t += par[1] * 0.72;
            });
        } catch (e) {
            // sem áudio neste aparelho: a cabine segue muda
        }
    }

    var SOM_OK = [[392, 0.09], [494, 0.13]];
    var SOM_RECUSA = [[196, 0.13]];

    /* ---- LEDs da fenda (piscaLeds): acendem em ordem sorteada; sem movimento reduzido ---- */
    function piscaLeds() {
        if (reduzMovimento()) {
            return;
        }
        var L = leds.slice();
        for (var i = L.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var x = L[i];
            L[i] = L[j];
            L[j] = x;
        }
        L.forEach(function (led, k) {
            setTimeout(function () { led.classList.add('uLed'); }, k * 105);
            setTimeout(function () { led.classList.remove('uLed'); }, k * 105 + 240);
        });
    }

    function marcaTecla(chave) {
        var t = raiz.querySelector('[data-u-tecla="' + chave + '"]');
        if (!t) {
            return;
        }
        t.classList.add('uPr');
        setTimeout(function () { t.classList.remove('uPr'); }, 110);
    }

    /* ---- Dados do cargo atual ---- */
    function pautas() {
        return c.sim ? D.simulacao : D.pautas;
    }

    function pautaAtual() {
        return pautas()[c.i];
    }

    function vagasDa(p) {
        return p.vagas > 1 ? p.vagas : 1;
    }

    /* Número da opção com zeros à esquerda, pelos dígitos da pauta (a coluna é inteira). */
    function numeroDe(p, o) {
        var t = String(o.numero);
        while (t.length < digitosDa(p)) {
            t = '0' + t;
        }
        return t;
    }

    function digitosDa(p) {
        if (p.digitos > 0) {
            return p.digitos;
        }
        var n = 1;
        p.opcoes.forEach(function (o) {
            if (!o.abstencao) {
                n = Math.max(n, String(o.numero).length);
            }
        });
        return n;
    }

    function opcaoPorNumero(p, numero) {
        for (var k = 0; k < p.opcoes.length; k++) {
            if (!p.opcoes[k].abstencao && numeroDe(p, p.opcoes[k]) === numero) {
                return p.opcoes[k];
            }
        }
        return null;
    }

    function opcaoAbstencao(p) {
        for (var k = 0; k < p.opcoes.length; k++) {
            if (p.opcoes[k].abstencao) {
                return p.opcoes[k];
            }
        }
        return null;
    }

    function jaEscolhida(o) {
        return c.escolhas.some(function (e) { return o.nulo ? e.nulo && e.numero === o.numero : !e.nulo && e.id === o.id; });
    }

    function podeTeclar() {
        return !!c && c.fase === 'urna' && !c.ver;
    }

    function iniciais(nome) {
        return nome.split(/\s+/).map(function (p) { return p.charAt(0); }).slice(-2).join('').toUpperCase();
    }

    /* ---- Desenho da tela (visor) por fase ---- */
    function centro() {
        var d = el('div', 'uFade uCentro');
        return d;
    }

    function telaInicio() {
        var d = centro();
        var logo = el('img', 'uLogo');
        logo.src = D.logo;
        logo.alt = 'BlueJus';
        d.appendChild(logo);
        d.appendChild(el('b', 'uInicioNome', D.assembleia));
        var ap = el('div', 'uInicioDica');
        var partes = texto('inicio_aperte', {confirma: '\u0000'}).split('\u0000');
        ap.appendChild(document.createTextNode(partes[0]));
        ap.appendChild(el('b', 'uVerde', texto('confirma')));
        ap.appendChild(document.createTextNode(partes[1] || ''));
        d.appendChild(ap);
        d.appendChild(botao(texto('conhecer'), 'bi-person-lines-fill', '', function () { definir({ver: true}); }));
        return d;
    }

    function telaVotando() {
        var p = pautaAtual();
        var dig = digitosDa(p);
        var achou = (!c.branco && c.dig.length === dig) ? opcaoPorNumero(p, c.dig) : null;
        var completo = c.dig.length === dig;
        var invalido = !c.branco && completo && !achou;
        var repetido = !c.branco && completo && jaEscolhida(achou || {nulo: true, numero: c.dig});
        var wrap = el('div', 'uVotando');
        var linha = el('div', 'uFade uLinha');
        var col = el('div', 'uCol');
        col.appendChild(el('div', 'uSeuVoto', texto('seu_voto_para')));
        col.appendChild(el('div', 'uCargo', p.titulo));
        if (vagasDa(p) > 1) {
            col.appendChild(el('div', 'uEscolhaDe', texto('escolha_de', {k: String(c.escolhas.length + 1), n: String(vagasDa(p))})));
            c.escolhas.forEach(function (o) {
                col.appendChild(el('div', 'uEscolhido', numeroDe(p, o) + ' · ' + o.rotulo));
            });
        }
        if (!c.branco) {
            var nums = el('div', 'uNums');
            nums.appendChild(el('span', 'uNumRot', texto('numero')));
            for (var k = 0; k < dig; k++) {
                nums.appendChild(el('span', 'uNum' + (k === c.dig.length ? ' uCur' : ''), c.dig.charAt(k)));
            }
            col.appendChild(nums);
        }
        if (c.branco) {
            col.appendChild(el('div', 'uFade uGrande', texto('abstencao')));
        } else if (repetido) {
            var rep = el('div', 'uFade');
            rep.appendChild(el('div', 'uGrande', texto(achou ? 'nome_repetido' : 'numero_repetido')));
            rep.appendChild(el('div', 'uInvalidoDica', texto('numero_invalido_dica')));
            col.appendChild(rep);
        } else if (invalido) {
            var n = el('div', 'uFade');
            n.appendChild(el('div', 'uNumeroErrado', texto('numero_invalido')));
            n.appendChild(el('div', 'uGrande', texto('voto_nulo')));
            col.appendChild(n);
        } else if (achou) {
            var a = el('div', 'uFade uAchou');
            a.appendChild(el('b', 'uAchouNome', achou.rotulo));
            col.appendChild(a);
        }
        linha.appendChild(col);
        wrap.appendChild(linha);
        if ((completo && !repetido) || c.branco || (c.escolhas.length > 0 && !c.dig)) {
            var rod = el('div', 'uRod uFade');
            rod.appendChild(document.createTextNode(texto('rodape_titulo')));
            rod.appendChild(document.createElement('br'));
            rod.appendChild(el('b', 'uVerde', texto('rodape_confirma').split(' ')[0]));
            rod.appendChild(document.createTextNode(' ' + texto('rodape_confirma').split(' ').slice(1).join(' ')));
            rod.appendChild(document.createElement('br'));
            rod.appendChild(el('b', 'uLaranja', texto('rodape_corrige').split(' ')[0]));
            rod.appendChild(document.createTextNode(' ' + texto('rodape_corrige').split(' ').slice(1).join(' ')));
            wrap.appendChild(rod);
        } else {
            var dica = el('div', 'uRod uRodDica');
            var s = el('span', 'uRodTx');
            s.appendChild(el('b', 'uOpcT', p.opcoes.filter(function (o) { return !o.abstencao; }).map(function (o) {
                return numeroDe(p, o) + ' = ' + o.rotulo;
            }).join('     ')));
            s.appendChild(document.createTextNode(vagasDa(p) > 1 ? texto('dica_varios', {n: String(vagasDa(p))}) : texto('dica')));
            dica.appendChild(s);
            dica.appendChild(botao(texto('conhecer'), 'bi-person-lines-fill', '', function () { definir({ver: true}); }));
            wrap.appendChild(dica);
        }
        return wrap;
    }

    function telaGrava() {
        var d = el('div', 'uFim');
        d.appendChild(el('div', 'uFimTitulo', c.sim ? texto('grava_titulo_simulacao') : texto('grava_titulo')));
        var prog = el('div', 'uProg');
        prog.appendChild(document.createElement('div'));
        d.appendChild(prog);
        d.appendChild(el('div', 'uFimSub', c.sim ? texto('grava_subtitulo_simulacao') : texto('grava_subtitulo')));
        return d;
    }

    function telaFim() {
        var d = el('div', 'uFim');
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'uAnel');
        svg.setAttribute('viewBox', '0 0 110 110');
        svg.setAttribute('aria-hidden', 'true');
        var circulo = document.createElementNS(ns, 'circle');
        circulo.setAttribute('cx', '55');
        circulo.setAttribute('cy', '55');
        circulo.setAttribute('r', '48');
        var caminho = document.createElementNS(ns, 'path');
        caminho.setAttribute('d', 'M34 57l15 15 28-31');
        svg.appendChild(circulo);
        svg.appendChild(caminho);
        d.appendChild(svg);
        d.appendChild(el('div', 'uFimGrande', texto('fim')));
        d.appendChild(el('div', 'uFimT', c.sim ? texto('fim_simulacao') : texto('fim_voto')));
        if (!c.sim && c.comprovantes.length > 0) {
            var cp = el('div', 'uComp');
            cp.appendChild(el('div', 'uCompRot', texto('comprovante_titulo')));
            c.comprovantes.forEach(function (x) {
                var l = el('div', 'uCompLinha');
                l.appendChild(el('span', 'uCompPauta', x.pauta));
                l.appendChild(el('b', 'uCompCodigo', x.codigo));
                cp.appendChild(l);
            });
            d.appendChild(cp);
            var cb = el('div', 'uFimAcoes');
            cb.appendChild(botao(texto('comprovante_imagem'), 'bi-image', '', baixarImagem));
            d.appendChild(cb);
        }
        var acoes = el('div', 'uFimAcoes');
        if (c.sim) {
            acoes.appendChild(botao(texto('simular_de_novo'), 'bi-arrow-repeat', '', function () { abrir(true); }));
        }
        acoes.appendChild(botao(texto('concluir'), '', 'vP', fechar));
        d.appendChild(acoes);
        return d;
    }

    /* ---- Comprovante em imagem (PNG gerado no aparelho; nada sobe para o servidor) ---- */
    function baixarImagem() {
        var lista = c.comprovantes;
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
        g.fillText(texto('imagem_titulo'), 40, 70);
        g.fillStyle = '#12242f';
        g.font = '24px Arial, Helvetica, sans-serif';
        g.fillText(D.assembleia, 40, 112);
        g.fillStyle = '#34505f';
        g.font = '20px Arial, Helvetica, sans-serif';
        g.fillText(new Date().toLocaleString('pt-BR'), 40, 146);
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
        g.fillText(texto('imagem_rodape'), 40, cv.height - 28);
        cv.toBlob(function (blob) {
            if (!blob) {
                return;
            }
            var u = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = u;
            a.download = texto('imagem_arquivo') || 'comprovante.png';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(u); }, 3000);
        }, 'image/png');
    }

    function telaErro() {
        var d = el('div', 'uFim');
        var i = icone('bi-exclamation-octagon');
        i.className += ' uErroIc';
        d.appendChild(i);
        d.appendChild(el('div', 'uErroT', texto('erro_titulo')));
        d.appendChild(el('div', 'uErroTx', c.erro || texto('erro_generico')));
        d.appendChild(botao(texto('voltar'), '', '', fechar));
        return d;
    }

    function telaCandidaturas() {
        var d = el('div', 'uFim uVer');
        var topo = el('div', 'uVerTopo');
        topo.appendChild(el('b', 'uVerTit', texto('candidaturas')));
        topo.appendChild(botao(texto('voltar'), 'bi-arrow-left', '', function () {
            if (c.soVer) {
                fechar();
                return;
            }
            definir({ver: false});
        }));
        d.appendChild(topo);
        D.simulacao.forEach(function (p) {
            var g = el('div', 'uVerGrupo');
            g.appendChild(el('b', 'uVerNome', p.titulo));
            p.opcoes.forEach(function (o) {
                var card = el('div', 'uCard');
                card.appendChild(el('span', 'vAv', iniciais(o.rotulo)));
                var tx = el('div', 'uCardTx');
                tx.appendChild(el('b', 'uCardNome', o.abstencao ? o.rotulo : numeroDe(p, o) + ' · ' + o.rotulo));
                card.appendChild(tx);
                g.appendChild(card);
            });
            d.appendChild(g);
        });
        return d;
    }

    function falaAtual() {
        if (!c) {
            return '';
        }
        if (c.fase === 'fim') {
            return c.sim ? texto('fala_fim_simulacao') : texto('fala_fim');
        }
        if (c.fase !== 'urna') {
            return '';
        }
        var p = pautaAtual();
        var dig = digitosDa(p);
        if (c.branco) {
            return texto('fala_abstencao');
        }
        if (c.dig.length === dig) {
            var o = opcaoPorNumero(p, c.dig);
            if (jaEscolhida(o || {nulo: true, numero: c.dig})) {
                return texto(o ? 'nome_repetido' : 'numero_repetido');
            }
            return o ? texto('fala_opcao', {opcao: o.rotulo}) : texto('fala_invalido');
        }
        return texto('fala_digite', {pauta: p.titulo});
    }

    function desenhar() {
        if (!c) {
            return;
        }
        while (tela.firstChild) {
            tela.removeChild(tela.firstChild);
        }
        if (c.ver) {
            tela.appendChild(telaCandidaturas());
        } else if (c.fase === 'inicio') {
            tela.appendChild(telaInicio());
        } else if (c.fase === 'urna') {
            tela.appendChild(telaVotando());
        } else if (c.fase === 'grava') {
            tela.appendChild(telaGrava());
        } else if (c.fase === 'fim') {
            tela.appendChild(telaFim());
        } else if (c.fase === 'erro') {
            tela.appendChild(telaErro());
        }
        faixa.hidden = !c.sim || !!c.soVer;
        fala.textContent = falaAtual();
    }

    function definir(mudancas) {
        Object.keys(mudancas).forEach(function (k) { c[k] = mudancas[k]; });
        desenhar();
    }

    /* ---- Teclas (digito, branco, corrige, confirma do desenho) ---- */
    function digito(d) {
        if (!podeTeclar()) {
            return;
        }
        marcaTecla(d);
        var p = pautaAtual();
        if (c.branco || c.dig.length >= digitosDa(p)) {
            return;
        }
        tocar([[440, 0.06]], 0.05);
        definir({dig: c.dig + d});
    }

    function branco() {
        if (!podeTeclar()) {
            return;
        }
        marcaTecla('b');
        if (c.dig || c.escolhas.length > 0 || !opcaoAbstencao(pautaAtual())) {
            tocar(SOM_RECUSA, 0.05);
            return;
        }
        tocar([[440, 0.06]], 0.05);
        definir({branco: true});
    }

    function corrige() {
        if (!podeTeclar()) {
            return;
        }
        marcaTecla('c');
        tocar([[294, 0.09]], 0.06);
        // Com número digitado ou abstenção, limpa só isso; sem nada digitado, desfaz o último nome escolhido.
        var restantes = (c.dig || c.branco) ? c.escolhas : c.escolhas.slice(0, -1);
        definir({dig: '', branco: false, escolhas: restantes});
    }

    function confirma() {
        if (c && c.fase === 'inicio' && !c.ver) {
            piscaLeds();
            marcaTecla('o');
            tocar(SOM_OK);
            definir({fase: 'urna'});
            return;
        }
        if (!podeTeclar()) {
            return;
        }
        marcaTecla('o');
        var p = pautaAtual();
        // Várias vagas: sem número digitado, CONFIRMA fecha a pauta com os nomes já escolhidos (se houver algum).
        if (!c.branco && !c.dig && c.escolhas.length > 0) {
            avancar(p, c.escolhas);
            return;
        }
        var escolhida = c.branco ? opcaoAbstencao(p) : opcaoPorNumero(p, c.dig);
        if (!c.branco && c.dig.length === digitosDa(p) && !escolhida) {
            escolhida = {nulo: true, numero: c.dig, rotulo: texto('voto_nulo')};
        }
        // Número incompleto e escolha repetida não avançam; número inexistente completo vale como nulo.
        if (!escolhida || (!c.branco && jaEscolhida(escolhida))) {
            tocar(SOM_RECUSA, 0.05);
            return;
        }
        piscaLeds();
        var escolhas = c.branco ? [escolhida] : c.escolhas.concat([escolhida]);
        if (!c.branco && escolhas.length < vagasDa(p)) {
            tocar(SOM_OK);
            definir({escolhas: escolhas, dig: ''});
            return;
        }
        avancar(p, escolhas);
    }

    /* Fecha a pauta com as opções escolhidas e vai à próxima, ou grava se era a última. */
    function avancar(p, escolhas) {
        var votos = c.votos.concat([{pauta: p.id, opcoes: escolhas.filter(function (o) { return !o.nulo; }).map(function (o) { return o.id; }), nulos: escolhas.filter(function (o) { return o.nulo; }).map(function (o) { return o.numero; })}]);
        if (c.i + 1 < pautas().length) {
            tocar(SOM_OK);
            definir({votos: votos, i: c.i + 1, dig: '', branco: false, escolhas: []});
            return;
        }
        definir({votos: votos, fase: 'grava'});
        gravar(votos);
    }

    function esperar(ms) {
        return new Promise(function (ok) { setTimeout(ok, ms); });
    }

    function tituloDaPauta(id) {
        for (var k = 0; k < D.pautas.length; k++) {
            if (D.pautas[k].id === id) {
                return D.pautas[k].titulo;
            }
        }
        return '';
    }

    function enviarUm(voto) {
        var corpo = new URLSearchParams();
        corpo.set('_token', D.csrf);
        voto.opcoes.forEach(function (id) { corpo.append('opcao[]', String(id)); });
        (voto.nulos || []).forEach(function (numero) { corpo.append('numero_nulo[]', numero); });
        return fetch(D.urlVotar.replace('__PAUTA__', String(voto.pauta)), {
            method: 'POST',
            body: corpo,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }, function () {
            throw new Error(texto('erro_rede'));
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.ok) {
                    throw new Error(j.mensagem || texto('erro_generico'));
                }
                return j.comprovante || '';
            });
        });
    }

    function gravar(votos) {
        var sessao = c;
        var inicio = Date.now();
        var trabalho = Promise.resolve();
        if (!sessao.sim) {
            votos.forEach(function (v) {
                trabalho = trabalho.then(function () {
                    return enviarUm(v).then(function (codigo) {
                        if (codigo) {
                            sessao.comprovantes.push({pauta: tituloDaPauta(v.pauta), codigo: codigo});
                        }
                    });
                });
            });
        }
        trabalho.then(function () {
            return esperar(Math.max(0, 1100 - (Date.now() - inicio)));
        }).then(function () {
            if (c !== sessao) {
                return;
            }
            definir({fase: 'fim', votos: []});
            tocar([[392, 0.2], [494, 0.2], [587, 0.34]], 0.08);
        }).catch(function (erro) {
            if (c !== sessao) {
                return;
            }
            definir({fase: 'erro', erro: erro.message, votos: []});
            tocar([[196, 0.22]], 0.07);
        });
    }

    /* ---- Abrir e fechar ---- */
    function abrir(simulacao, soVer) {
        if ((!simulacao && D.pautas.length === 0) || D.simulacao.length === 0) {
            return;
        }
        if (!c) {
            ultimoFoco = document.activeElement;
        }
        c = {fase: 'inicio', sim: !!simulacao, soVer: !!soVer, i: 0, dig: '', branco: false, escolhas: [], votos: [], ver: !!soVer, erro: '', comprovantes: []};
        raiz.hidden = false;
        document.body.classList.add('uAberta');
        desenhar();
        botoes.confirma.focus();
    }

    function fechar() {
        var gravou = !!c && !c.sim && (c.fase === 'fim' || c.fase === 'erro');
        c = null;
        raiz.hidden = true;
        document.body.classList.remove('uAberta');
        if (gravou) {
            window.location.reload();
            return;
        }
        if (ultimoFoco && ultimoFoco.focus) {
            ultimoFoco.focus();
        }
    }

    /* ---- Ligações ---- */
    raiz.querySelectorAll('[data-u-tecla]').forEach(function (t) {
        var chave = t.getAttribute('data-u-tecla');
        if (/^[0-9]$/.test(chave)) {
            t.addEventListener('click', function () { digito(chave); });
        }
    });
    botoes.branco.addEventListener('click', branco);
    botoes.corrige.addEventListener('click', corrige);
    botoes.confirma.addEventListener('click', confirma);

    function atualizaSom() {
        var i = botaoSom.querySelector('i');
        i.className = 'bi ' + (som ? 'bi-volume-up' : 'bi-volume-mute');
        botaoSom.setAttribute('aria-label', som ? texto('som_desligar') : texto('som_ligar'));
    }
    botaoSom.addEventListener('click', function () {
        som = !som;
        atualizaSom();
    });
    atualizaSom();

    document.addEventListener('keydown', function (evento) {
        if (!c || evento.ctrlKey || evento.metaKey || evento.altKey) {
            return;
        }
        var naBotao = evento.target && evento.target.tagName === 'BUTTON';
        if (/^[0-9]$/.test(evento.key)) {
            evento.preventDefault();
            digito(evento.key);
        } else if (evento.key === 'b' || evento.key === 'B') {
            evento.preventDefault();
            branco();
        } else if (evento.key === 'Backspace' || evento.key === 'Delete') {
            evento.preventDefault();
            corrige();
        } else if (evento.key === 'Enter' && (!naBotao || evento.target.hasAttribute('data-u-tecla'))) {
            evento.preventDefault();
            confirma();
        }
    });

    document.querySelectorAll('[data-vc-abrir-cabine]').forEach(function (b) {
        b.addEventListener('click', function () { abrir(false); });
    });
    document.querySelectorAll('[data-vc-abrir-simulacao]').forEach(function (b) {
        b.addEventListener('click', function () { abrir(true); });
    });
    document.querySelectorAll('[data-vc-abrir-candidaturas]').forEach(function (b) {
        b.addEventListener('click', function () {
            abrir(true, true);
        });
    });
})();
