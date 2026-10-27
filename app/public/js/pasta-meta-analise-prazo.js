/* Análise do prazo da "Nova meta" (desenho 02 de 22/10, L.98-103, L.126-174 e L.4106-4155).

   O servidor calcula (POST tarefa_analise_do_prazo, com o mesmo CSRF do #formCriarTarefa); este script só
   pergunta, mostra e guarda o que a pessoa decidiu: origem do prazo (publicação e ciência), prazo jurídico
   informado e confirmação. Resposta `analise: null` esconde tudo. Sem leitura de documentos.

   No envio da meta, o inline do show.html.twig chama `PastaMetaAnalisePrazo.acrescentar(formData)` e, no 422,
   `abrirParaConferir()`. Os ids e names do formulário são contrato e ficam como estão. */
(function () {
    'use strict';

    var modal = document.getElementById('modalCriarTarefa');
    var form = document.getElementById('formCriarTarefa');
    if (!modal || !form) { return; }
    var caixa = modal.querySelector('[data-analise-prazo]');
    if (!caixa) { return; }

    var URL_ANALISE = caixa.getAttribute('data-url');
    var chipWrap = modal.querySelector('[data-ap-chip-wrap]');
    var chip = modal.querySelector('[data-ap-chip]');
    var chipDica = modal.querySelector('[data-ap-chip-dica]');
    var alternar = modal.querySelector('[data-ap-alternar]');
    var campoPrazo = document.getElementById('tarefaPrazo');
    var campoTitulo = document.getElementById('tarefaTitulo');
    var campoDescricao = document.getElementById('tarefaDescricao');
    var campoResp = document.getElementById('tarefaResponsavel');

    function q(sel) { return caixa.querySelector(sel); }
    var el = {
        tipo: q('[data-ap-tipo]'), atualizada: q('[data-ap-atualizada]'), prio: q('[data-ap-prio]'),
        conferir: q('[data-ap-conferir]'), jurInput: q('[data-ap-jur-input]'), jurBtn: q('[data-ap-jur-btn]'),
        jurTxt: q('[data-ap-jur-txt]'), estado: q('[data-ap-estado]'), estadoIco: q('[data-ap-estado-ico]'),
        estadoTxt: q('[data-ap-estado-txt]'), dias: q('[data-ap-dias]'), folga: q('[data-ap-folga]'),
        tip: q('[data-ap-tip-caixa]'), tipTit: q('[data-ap-tip-tit]'), tipLinhas: q('[data-ap-tip-linhas]'),
        confPainel: q('[data-ap-conf-painel]'), confLinhas: q('[data-ap-conf-linhas]'),
        confirmar: q('[data-ap-confirmar]'), corrigir: q('[data-ap-corrigir]'), incerto: q('[data-ap-incerto]'),
        alt: q('[data-ap-alt]'), nota: q('[data-ap-nota]'), origemBtn: q('[data-ap-origem-btn]'),
        origem: q('[data-ap-origem]'), pub: q('[data-ap-pub]'), ciencia: q('[data-ap-ciencia]'),
        apr: q('[data-ap-apr]'), aprTxt: q('[data-ap-apr-txt]'), ckBloco: q('[data-ap-ck-bloco]'),
        ckLinhas: q('[data-ap-ck-linhas]')
    };

    /* Cores do desenho (EST e PC, L.4119 e L.4121). */
    var ESTADOS = {
        informado: ['#e5f1f7', '#0b5f86', 'bi-person-check', 'estado_informado'],
        confirmado: ['#e6f4ec', '#1c6b43', 'bi-patch-check-fill', 'estado_confirmado'],
        estimado: ['#efeafc', '#4a33a0', 'bi-stars', 'estado_estimado'],
        necessita_conferencia: ['#fdeadb', '#a14b0b', 'bi-exclamation-circle', 'estado_conferencia'],
        sem_prazo_legal: ['#eef2f5', '#5f7684', 'bi-dash-circle', 'estado_sem_prazo']
    };
    var PRIORIDADES = {
        'Crítico': ['#fbe3e1', '#a3241a'], 'Urgente': ['#fdeadb', '#a14b0b'], 'Importante': ['#fff3d6', '#7a5600'],
        'Atenção': ['#e5f1f7', '#0b5f86'], 'Normal': ['#e6f4ec', '#1c6b43']
    };

    var estado = zerar();
    var analise = null;
    var sugestaoAnterior = '';
    var sequencia = 0;
    var temporizador = null;
    var temporizadorAtualizada = null;

    function zerar() {
        return { pub: '', ciencia: '', jur: '', confirmado: false, aberta: false, conferindo: false, editJur: false, origemAberta: false, tip: '', nota: '' };
    }

    var PREFIXO = 'pasta_scripts.js.analise_prazo.';
    function t(chave, params) { return BJ_T.t(PREFIXO + chave, params); }

    function br(iso) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}/.test(iso)) { return iso || ''; }
        return iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);
    }

    function diasUteis(n) { return n === 1 ? t('dia_util', { n: n }) : t('dias_uteis', { n: n }); }

    function limpar(no) { while (no.firstChild) { no.removeChild(no.firstChild); } }

    function mostrar(no, sim) { no.hidden = !sim; }

    /* ── Chamada ao servidor ─────────────────────────────────────────────── */
    function corpo() {
        var d = new FormData();
        var token = form.querySelector('input[name="_token"]');
        d.append('_token', token ? token.value : '');
        d.append('titulo', campoTitulo ? campoTitulo.value : '');
        d.append('descricao', campoDescricao ? campoDescricao.value : '');
        d.append('data_publicacao', estado.pub);
        d.append('data_ciencia', estado.ciencia);
        d.append('prazo_juridico', estado.jur);
        if (estado.confirmado && estado.jur) { d.append('analise_confirmada', '1'); }
        return d;
    }

    /* A mudança de origem/jurídico pendente sobrevive a digitações no título dentro do mesmo debounce. */
    var pendenteOrigem = false;

    function agendar(aoMudarOrigem) {
        if (aoMudarOrigem) { pendenteOrigem = aoMudarOrigem; }
        clearTimeout(temporizador);
        temporizador = setTimeout(function () {
            var o = pendenteOrigem;
            pendenteOrigem = false;
            consultar(o);
        }, 300);
    }

    function consultar(aoMudarOrigem) {
        var minha = ++sequencia;
        fetch(URL_ANALISE, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: corpo() })
            .then(function (r) { return r.ok ? r.json() : { analise: null }; })
            .then(function (res) {
                if (minha !== sequencia) { return; }
                receber(res && res.analise ? res.analise : null, aoMudarOrigem);
            })
            .catch(function () { /* sem análise: a meta continua criável pelo servidor */ });
    }

    /* Só troca o prazo da meta se ele ainda era a sugestão anterior (dc L.4109). */
    function receber(nova, aoMudarOrigem) {
        var anterior = analise;
        analise = nova;
        if (!nova) {
            sugestaoAnterior = '';
            desenhar();
            return;
        }
        if (aoMudarOrigem && anterior) {
            var segue = campoPrazo && (!campoPrazo.value || campoPrazo.value === sugestaoAnterior);
            if (typeof aoMudarOrigem === 'string') {
                estado.nota = aoMudarOrigem;
            } else if (nova.prazoInterno !== anterior.prazoInterno) {
                estado.nota = segue
                    ? t('nota_prazo_atualizado', { data: br(nova.prazoInterno) })
                    : t('nota_nova_sugestao', { data: br(nova.prazoInterno) });
            } else {
                estado.nota = '';
            }
            if (segue && campoPrazo && nova.prazoInterno && campoPrazo.value !== nova.prazoInterno) {
                campoPrazo.value = nova.prazoInterno;
                campoPrazo.dispatchEvent(new Event('change', { bubbles: true }));
            }
            piscarAtualizada();
        }
        sugestaoAnterior = nova.prazoInterno || '';
        desenhar();
    }

    function piscarAtualizada() {
        mostrar(el.atualizada, true);
        clearTimeout(temporizadorAtualizada);
        temporizadorAtualizada = setTimeout(function () {
            mostrar(el.atualizada, false);
            estado.nota = '';
            mostrar(el.nota, false);
        }, 2600);
    }

    /* ── Desenho ─────────────────────────────────────────────────────────── */
    function jurTexto(a) {
        if (!a.prazoJuridico) { return t('nao_identificado'); }
        return (a.aproximado ? '≈ ' : '') + br(a.prazoJuridico);
    }

    function folgaTexto(a) {
        return a.folga !== null && a.folga !== undefined ? diasUteis(a.folga) : t('sem_prazo_legal');
    }

    function linhaConf(rotulo, valor) {
        var l = document.createElement('div');
        l.className = 'ps-ap-conf-linha';
        var i = document.createElement('i');
        i.className = 'bi bi-check2';
        i.setAttribute('aria-hidden', 'true');
        var s = document.createElement('span');
        s.textContent = rotulo;
        var b = document.createElement('b');
        b.textContent = valor;
        l.appendChild(i); l.appendChild(s); l.appendChild(b);
        return l;
    }

    function linhaCk(texto) {
        var l = document.createElement('div');
        l.className = 'ps-ap-ck-linha';
        var i = document.createElement('i');
        i.className = 'bi bi-check2-square';
        i.setAttribute('aria-hidden', 'true');
        var s = document.createElement('span');
        s.textContent = texto;
        l.appendChild(i); l.appendChild(s);
        return l;
    }

    function semResponsavel() {
        return !campoResp || campoResp.selectedOptions.length === 0;
    }

    function itensDeConferencia(a) {
        var itens = [];
        if (a.estado === 'necessita_conferencia') { itens.push(t('ck_ciencia')); }
        itens.push(a.prazoJuridico ? t('ck_feriados', { data: br(a.prazoJuridico) }) : t('ck_existe_prazo'));
        if (typeof a.diasAteOInterno === 'number' && a.diasAteOInterno <= 3) { itens.push(t('ck_apertado')); }
        if (semResponsavel()) { itens.push(t('ck_responsavel')); }
        return itens;
    }

    function textosDaDica(chave) {
        if (!analise) { return null; }
        var a = analise;
        if (chave === 'jur') { return [t('tip_jur_titulo'), t('tip_jur_1'), t('tip_jur_2'), t('tip_jur_3')]; }
        if (chave === 'dias') {
            var n = a.diasUteisAteOJuridico != null ? a.diasUteisAteOJuridico : 0;
            var feriados = a.feriadosNoPeriodo || 0;
            var informado = a.estado === 'informado' || a.estado === 'confirmado';
            var cal = feriados > 1 ? t('tip_dias_calendario_varios', { n: feriados })
                : feriados === 1 ? t('tip_dias_calendario_um') : t('tip_dias_calendario');
            return [t('tip_dias_titulo'), t(informado ? 'tip_dias_informado' : 'tip_dias_estimado', { n: n }), cal];
        }
        if (chave === 'folga') {
            return [t('tip_folga_titulo'), a.folga ? t('tip_folga_com', { folga: diasUteis(a.folga) }) : t('tip_folga_sem')];
        }
        if (chave === 'prio') { return [a.prioridade, t('tip_prio_1'), t('tip_prio_2')]; }
        return null;
    }

    function desenhar() {
        var a = analise;
        mostrar(chipWrap, !!a);
        mostrar(alternar, !!a);
        if (!a) {
            mostrar(caixa, false);
            return;
        }

        /* Chip e botão "Análise" */
        var interno = a.prazoInterno || '';
        chip.querySelector('[data-ap-chip-txt]').textContent = t('chip', { data: br(interno).slice(0, 5) });
        chip.classList.toggle('is-ativo', !!campoPrazo && campoPrazo.value === interno);
        var dica = t('chip_dica', { data: br(interno), porque: a.porque || '' });
        chip.setAttribute('aria-label', dica);
        chipDica.textContent = dica;
        var togDica = estado.aberta ? t('tog_ocultar') : t('tog_ver');
        alternar.title = togDica;
        alternar.setAttribute('aria-label', togDica);
        alternar.setAttribute('aria-expanded', estado.aberta ? 'true' : 'false');
        alternar.querySelector('[data-ap-alternar-ico]').className = 'bi ' + (estado.aberta ? 'bi-chevron-up' : 'bi-chevron-down');

        mostrar(caixa, estado.aberta);
        if (!estado.aberta) { return; }

        /* Cabeçalho */
        el.tipo.textContent = a.tipo || '';
        var pc = PRIORIDADES[a.prioridade] || PRIORIDADES.Normal;
        el.prio.textContent = a.prioridade || '';
        el.prio.style.background = pc[0];
        el.prio.style.color = pc[1];
        var confirmado = a.estado === 'confirmado';
        el.conferir.textContent = confirmado ? t('confirmado_botao') : t('conferir');
        el.conferir.classList.toggle('is-confirmado', confirmado);
        el.conferir.setAttribute('aria-expanded', estado.conferindo ? 'true' : 'false');

        /* Cards */
        el.jurTxt.textContent = jurTexto(a);
        mostrar(el.jurInput, estado.editJur);
        mostrar(el.jurBtn, !estado.editJur);
        if (estado.editJur && el.jurInput.value !== (a.prazoJuridico || '')) { el.jurInput.value = a.prazoJuridico || ''; }
        var est = ESTADOS[a.estado] || ESTADOS.sem_prazo_legal;
        el.estado.style.background = est[0];
        el.estado.style.color = est[1];
        el.estadoIco.className = 'bi ' + est[2];
        el.estadoTxt.textContent = t(est[3]);
        el.dias.textContent = a.diasUteisAteOJuridico != null ? String(a.diasUteisAteOJuridico) : t('sem_prazo_legal');
        el.folga.textContent = folgaTexto(a);

        /* Dica dos cards */
        var linhas = textosDaDica(estado.tip);
        mostrar(el.tip, !!linhas);
        if (linhas) {
            el.tipTit.textContent = linhas[0];
            limpar(el.tipLinhas);
            linhas.slice(1).forEach(function (txt) {
                var d = document.createElement('div');
                d.textContent = txt;
                el.tipLinhas.appendChild(d);
            });
        }

        /* Painel Conferir */
        mostrar(el.confPainel, estado.conferindo);
        if (estado.conferindo) {
            var ref = a.origem === 'ciencia' ? 'ref_ciencia' : a.origem === 'publicacao' ? 'ref_publicacao' : 'ref_hoje';
            var jurSemAprox = a.prazoJuridico ? br(a.prazoJuridico) : t('nao_identificado');
            limpar(el.confLinhas);
            [
                [t('conf_referencia'), t(ref, { data: br(a.termoInicial) })],
                [t('conf_prazo'), jurSemAprox],
                [t('conf_dias'), a.diasUteisAteOJuridico != null ? String(a.diasUteisAteOJuridico) : t('nd')],
                [t('conf_feriados'), String(a.feriadosNoPeriodo || 0).padStart(2, '0')],
                [t('conf_folga'), a.folga !== null && a.folga !== undefined ? diasUteis(a.folga) : t('nd')],
                [t('conf_interno'), br(a.prazoInterno)]
            ].forEach(function (par) { el.confLinhas.appendChild(linhaConf(par[0], par[1])); });
        }
        el.confirmar.disabled = !a.prazoJuridico;

        /* Alertas e notas */
        mostrar(el.incerto, a.estado === 'necessita_conferencia');
        var temAlt = !!a.alternativa && !!estado.jur;
        mostrar(el.alt, temAlt);
        if (temAlt) { el.alt.textContent = t(confirmado ? 'alt_confirmado' : 'alt_informado', { data: br(a.alternativa) }); }
        mostrar(el.nota, !!estado.nota);
        el.nota.textContent = estado.nota;

        /* Origem do prazo */
        el.origemBtn.textContent = estado.origemAberta ? t('origem_ocultar') : (estado.pub || estado.ciencia ? t('origem_informada') : t('origem_informar'));
        el.origemBtn.setAttribute('aria-expanded', estado.origemAberta ? 'true' : 'false');
        mostrar(el.origem, estado.origemAberta);
        if (el.pub.value !== estado.pub) { el.pub.value = estado.pub; }
        if (el.ciencia.value !== estado.ciencia) { el.ciencia.value = estado.ciencia; }

        /* Aprendizado do escritório: só aparece se o servidor mandar o texto. */
        var apr = typeof a.aprendizado === 'string' ? a.aprendizado : '';
        mostrar(el.apr, apr !== '');
        el.aprTxt.textContent = apr;

        /* Antes de salvar, confira */
        var ck = itensDeConferencia(a);
        mostrar(el.ckBloco, ck.length > 0);
        limpar(el.ckLinhas);
        ck.forEach(function (txt) { el.ckLinhas.appendChild(linhaCk(txt)); });
    }

    /* ── Interações ──────────────────────────────────────────────────────── */
    function mudouOrigem(nota) {
        agendar(nota || true);
    }

    alternar.addEventListener('click', function () { estado.aberta = !estado.aberta; desenhar(); });

    chip.addEventListener('click', function () {
        if (!analise || !campoPrazo || !analise.prazoInterno) { return; }
        campoPrazo.value = analise.prazoInterno;
        campoPrazo.dispatchEvent(new Event('change', { bubbles: true }));
        chipDica.hidden = true;
        desenhar();
    });
    chipWrap.addEventListener('mouseenter', function () { chipDica.hidden = false; });
    chipWrap.addEventListener('mouseleave', function () { chipDica.hidden = true; });
    chip.addEventListener('focus', function () { chipDica.hidden = false; });
    chip.addEventListener('blur', function () { chipDica.hidden = true; });

    Array.prototype.forEach.call(caixa.querySelectorAll('[data-ap-tip]'), function (card) {
        card.addEventListener('mouseenter', function () { estado.tip = card.getAttribute('data-ap-tip'); desenhar(); });
        card.addEventListener('mouseleave', function () { estado.tip = ''; desenhar(); });
    });
    el.prio.addEventListener('mouseenter', function () { estado.tip = 'prio'; desenhar(); });
    el.prio.addEventListener('mouseleave', function () { estado.tip = ''; desenhar(); });

    el.conferir.addEventListener('click', function () {
        estado.conferindo = !estado.conferindo;
        estado.editJur = false;
        desenhar();
    });

    el.jurBtn.addEventListener('click', function () {
        estado.editJur = true;
        estado.conferindo = false;
        desenhar();
        el.jurInput.focus();
    });
    el.jurInput.addEventListener('change', function () {
        if (!el.jurInput.value) { return; }
        estado.jur = el.jurInput.value;
        estado.confirmado = false;
        estado.editJur = false;
        mudouOrigem();
        desenhar();
    });
    el.jurInput.addEventListener('blur', function () { estado.editJur = false; desenhar(); });

    el.confirmar.addEventListener('click', function () {
        if (!analise || !analise.prazoJuridico) { return; }
        estado.jur = analise.prazoJuridico;
        estado.confirmado = true;
        estado.conferindo = false;
        estado.editJur = false;
        mudouOrigem(t('nota_confirmada', { data: br(analise.prazoJuridico) }));
        desenhar();
    });
    el.corrigir.addEventListener('click', function () {
        estado.conferindo = false;
        estado.origemAberta = true;
        estado.editJur = true;
        desenhar();
        el.jurInput.focus();
    });

    el.origemBtn.addEventListener('click', function () { estado.origemAberta = !estado.origemAberta; desenhar(); });
    el.pub.addEventListener('change', function () { estado.pub = el.pub.value; mudouOrigem(); });
    el.ciencia.addEventListener('change', function () { estado.ciencia = el.ciencia.value; mudouOrigem(); });

    /* Texto da meta e prazo: nova consulta (sem trocar o prazo da meta). Responsáveis só refazem a lista final. */
    [campoTitulo, campoDescricao].forEach(function (c) {
        if (!c) { return; }
        c.addEventListener('input', function () { agendar(false); });
        c.addEventListener('change', function () { agendar(false); });
    });
    if (campoPrazo) { campoPrazo.addEventListener('change', function () { if (analise) { desenhar(); } }); }
    if (campoResp) { campoResp.addEventListener('change', function () { if (analise) { desenhar(); } }); }

    modal.addEventListener('show.bs.modal', function () { agendar(false); });
    modal.addEventListener('hidden.bs.modal', function () {
        clearTimeout(temporizador);
        pendenteOrigem = false;
        sequencia++;
        estado = zerar();
        analise = null;
        sugestaoAnterior = '';
        desenhar();
    });

    /* ── Contrato com o envio da meta ────────────────────────────────────── */
    window.PastaMetaAnalisePrazo = {
        acrescentar: function (dados) {
            if (!analise) { return; }
            dados.append('analise_prazo', '1');
            dados.append('data_publicacao', estado.pub);
            dados.append('data_ciencia', estado.ciencia);
            dados.append('prazo_juridico', estado.jur || '');
            if (estado.confirmado && estado.jur) { dados.append('analise_confirmada', '1'); }
        },
        abrirParaConferir: function () {
            if (!analise) { return; }
            estado.aberta = true;
            estado.conferindo = true;
            estado.editJur = false;
            desenhar();
        }
    };
}());
