/*
 * perfis.js: comportamento da lista e da edição de perfis (desenho "05 - Perfis 1.0").
 *
 * - linha inteira clicável abre a edição (o lápis e a lixeira têm o próprio clique);
 * - "Ver como Master / Primário" (só no HTML do Master): prévia que esconde a linha Master e
 *   troca os números do resumo (D7);
 * - confirmação interna do desenho no lugar do window.confirm (excluir perfil, promover e
 *   remover primário);
 * - "Marcar todos / Desmarcar todos" por cartão e o rodapé "N de M permissões ativas".
 *
 * Nenhum texto de decisão mora só aqui: o servidor revalida tudo (CSRF, posse, regras).
 * Os textos vêm do catálogo da página ({{ catalogo_js('perfis.js') }}) por BJ_T.t (spec idioma-do-sistema).
 */
(function () {
    'use strict';

    var COR_PERIGO = '#a3232b';
    var COR_AZUL = '#0a6fa0';

    function qs(sel, raiz) { return (raiz || document).querySelector(sel); }
    function qsa(sel, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(sel)); }

    // ── Confirmação interna ────────────────────────────────────────────────
    var conf = qs('[data-pf-conf]');
    var aoConfirmar = null;

    function fecharConf() {
        if (!conf) { return; }
        conf.hidden = true;
        aoConfirmar = null;
        qs('[data-pf-conf-extra]', conf).innerHTML = '';
    }

    /**
     * @param {{titulo:string, icone:string, cor:string, texto:string, botao:string, extra?:Node, semBotao?:boolean, aoConfirmar:function}} op
     */
    function abrirConf(op) {
        if (!conf) { return; }
        qs('[data-pf-conf-titulo]', conf).textContent = op.titulo;
        qs('[data-pf-conf-icone]', conf).className = 'bi ' + op.icone;
        qs('[data-pf-conf-cab]', conf).style.background = op.cor;
        qs('[data-pf-conf-texto]', conf).textContent = op.texto;
        var extra = qs('[data-pf-conf-extra]', conf);
        extra.innerHTML = '';
        if (op.extra) { extra.appendChild(op.extra); }
        var ok = qs('[data-pf-conf-ok]', conf);
        ok.textContent = op.botao;
        ok.style.background = op.cor;
        ok.hidden = !!op.semBotao;
        aoConfirmar = op.aoConfirmar;
        conf.hidden = false;
        (op.semBotao ? qs('[data-pf-conf-cancelar]', conf) : ok).focus();
    }

    if (conf) {
        conf.addEventListener('click', function (e) { if (e.target === conf) { fecharConf(); } });
        qs('[data-pf-conf-cancelar]', conf).addEventListener('click', fecharConf);
        qs('[data-pf-conf-ok]', conf).addEventListener('click', function () {
            var acao = aoConfirmar;
            if (acao) { acao(); }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !conf.hidden) { fecharConf(); } });
    }

    // ── Lista: aviso da hierarquia (C6, desenho L823) ──────────────────────
    // O que o perfil não pode mexer não abre: o clique mostra a mensagem do servidor por alguns segundos.
    var aviso = qs('[data-pf-aviso]');
    var avisoTimer = null;
    function mostrarAviso(texto) {
        if (!aviso) { return; }
        aviso.textContent = texto;
        aviso.hidden = false;
        window.clearTimeout(avisoTimer);
        avisoTimer = window.setTimeout(function () { aviso.hidden = true; }, 3500);
    }
    qsa('button[data-pf-recusa]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            mostrarAviso(btn.getAttribute('data-pf-recusa'));
        });
    });

    // ── Lista: linha clicável ──────────────────────────────────────────────
    qsa('[data-pf-linha]').forEach(function (linha) {
        linha.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form, input, select')) { return; }
            var href = linha.getAttribute('data-href');
            if (!href) {
                mostrarAviso(linha.getAttribute('data-pf-recusa') || '');
                return;
            }
            window.location.href = href;
        });
    });

    // ── Lista: Ver como (D7) ───────────────────────────────────────────────
    var verComo = qs('[data-pf-ver-como]');
    if (verComo) {
        qsa('[data-pf-visao]', verComo).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var souMaster = btn.getAttribute('data-pf-visao') === 'master';
                qsa('[data-pf-visao]', verComo).forEach(function (b) { b.classList.toggle('is-ativo', b === btn); });
                qsa('[data-pf-linha-master]').forEach(function (l) { l.classList.toggle('is-oculta', !souMaster); });
                // Os chips do resumo acompanham a prévia: os dois números vêm prontos do servidor.
                qsa('[data-pf-chip-n]').forEach(function (n) {
                    n.textContent = n.getAttribute(souMaster ? 'data-master' : 'data-primario');
                });
            });
        });
    }

    // ── Lista: excluir perfil ──────────────────────────────────────────────
    qsa('[data-pf-excluir]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var nome = btn.getAttribute('data-nome');
            var usu = parseInt(btn.getAttribute('data-usuarios') || '0', 10);
            var texto = BJ_T.t('perfis.js.excluir_texto', { nome: nome })
                + (usu ? ' ' + BJ_T.t('perfis.js.excluir_usuarios', { n: usu }) : '');
            abrirConf({
                titulo: BJ_T.t('perfis.js.excluir_titulo'), icone: 'bi-trash3', cor: COR_PERIGO, texto: texto, botao: BJ_T.t('perfis.js.excluir_titulo'),
                aoConfirmar: function () { btn.closest('form').submit(); }
            });
        });
    });

    // ── Edição: Marcar todos / resumo ──────────────────────────────────────
    var resumo = qs('[data-pf-resumo]');
    function atualizarResumo() {
        if (!resumo) { return; }
        var ativos = qsa('[data-pf-permissao]').filter(function (c) { return c.checked; }).length;
        resumo.textContent = BJ_T.t('perfis.js.resumo', { ativos: ativos, total: resumo.getAttribute('data-total') });
    }
    function atualizarCartao(cartao) {
        var caixas = qsa('[data-pf-permissao]', cartao);
        var todos = caixas.length > 0 && caixas.every(function (c) { return c.checked; });
        var btn = qs('[data-pf-todos]', cartao);
        if (btn) { btn.textContent = todos ? BJ_T.t('perfis.js.desmarcar_todos') : BJ_T.t('perfis.js.marcar_todos'); }
    }
    qsa('[data-pf-cartao]').forEach(function (cartao) {
        atualizarCartao(cartao);
        var btn = qs('[data-pf-todos]', cartao);
        if (btn) {
            btn.addEventListener('click', function () {
                var caixas = qsa('[data-pf-permissao]', cartao);
                var todos = caixas.every(function (c) { return c.checked; });
                caixas.forEach(function (c) { c.checked = !todos; });
                atualizarCartao(cartao);
                atualizarResumo();
            });
        }
        qsa('[data-pf-permissao]', cartao).forEach(function (c) {
            c.addEventListener('change', function () { atualizarCartao(cartao); atualizarResumo(); });
        });
    });

    // ── Edição do primário: promover sócio ─────────────────────────────────
    var formPromover = qs('[data-pf-form-promover]');
    if (formPromover) {
        var sel = qs('[data-pf-promover-select]', formPromover);
        var info = qs('[data-pf-promover-info]', formPromover);
        var msg = qs('[data-pf-promover-msg]', formPromover);
        sel.addEventListener('change', function () {
            var op = sel.options[sel.selectedIndex];
            info.textContent = sel.value ? op.getAttribute('data-info') : info.getAttribute('data-padrao');
            msg.textContent = msg.getAttribute('data-padrao');
            msg.classList.remove('is-erro');
        });
        qs('[data-pf-promover]', formPromover).addEventListener('click', function () {
            if (!sel.value) {
                msg.textContent = BJ_T.t('perfis.js.escolha_secundario');
                msg.classList.add('is-erro');

                return;
            }
            var nome = sel.options[sel.selectedIndex].getAttribute('data-nome');
            abrirConf({
                titulo: BJ_T.t('perfis.js.promover_titulo'), icone: 'bi-person-badge', cor: COR_AZUL,
                texto: BJ_T.t('perfis.js.promover_texto', { nome: nome }),
                botao: BJ_T.t('perfis.js.promover_confirmar'),
                aoConfirmar: function () { formPromover.submit(); }
            });
        });
    }

    // ── Edição do primário: remover sócio (pede o perfil de destino) ───────
    var modeloDestino = qs('template[data-pf-perfis-destino]');
    qsa('[data-pf-remover-primario]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('form');
            var extra = modeloDestino ? modeloDestino.content.cloneNode(true) : document.createDocumentFragment();
            var semDestino = !!extra.querySelector('[data-pf-sem-destino]');
            abrirConf({
                titulo: BJ_T.t('perfis.js.remover_titulo'), icone: 'bi-person-dash', cor: COR_PERIGO,
                texto: BJ_T.t('perfis.js.remover_texto', { nome: btn.getAttribute('data-nome') }),
                botao: BJ_T.t('perfis.js.remover_titulo'),
                extra: extra,
                semBotao: semDestino,
                aoConfirmar: function () {
                    var escolha = qs('[data-pf-conf-extra] [data-pf-destino-select]', conf);
                    if (!escolha || !escolha.value) { return; }
                    qs('[data-pf-destino]', form).value = escolha.value;
                    form.submit();
                }
            });
        });
    });
})();
