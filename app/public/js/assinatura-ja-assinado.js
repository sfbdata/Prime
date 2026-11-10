/* =============================================================================
   assinatura-ja-assinado.js: o documento que já chega assinado (etapa 5, bloco 5A), na tela interna da Assinatura.
   Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html (quadro do passo 3 dc:188, quadro do
   detalhe dc:218, presV dc:381, presOrig dc:383, presCarimbo dc:384, salvaBytes, dPrCar e dPrAv dc:466).
   Contrato: docs/specs/assinatura-documentos-etapa-5.md (E5-6) e o inventário 5A.

   O quadro vem pronto do servidor (ApresentacaoDaConferencia): título, cor, linhas, nota, aviso e se aceita carimbo.
   Aqui só se desenha, com os estilos do desenho, e se formata a data de cada assinatura no fuso do navegador (o `fm`
   do desenho). O passo 3 confere o PDF escolhido em POST /assinatura/pdf/conferir, sem gravar (o arquivo só sobe ao
   salvar o rascunho); o detalhe traz o quadro em `jaAssinado`.

   Botões do detalhe: "Baixar original com as assinaturas" baixa a última versão guardada como "{nome} - original.pdf";
   "Cópia com carimbo visível" (só quando o PDF aceita carimbo e nenhuma assinatura está alterada) pede o carimbo ao
   servidor (bloco 5B) e baixa como "{nome} - com carimbo.pdf". Falhas viram o aviso vermelho da tela, como no desenho.
   O assinatura.js liga tudo por window.BJAJaAssinado e passa o pedir() e o avisar() dele: nenhum fetch solto aqui.
   ============================================================================= */
(function () {
    'use strict';

    if (!window.BJ_T) { return; }

    const T = (chave, p) => BJ_T.t(['assinatura_eletronica_tela', 'js', chave].join('.'), p);
    const J = (chave, p) => T('ja_assinado.' + chave, p);
    const esc = t => String(t == null ? '' : t).replace(/[&<>\x22\x27]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\x22': '&quot;', '\x27': '&#39;' }[c]));
    const idioma = () => (window.BJFormato ? window.BJFormato.idioma() : 'pt-BR');
    const dataHora = v => { if (!v) { return ''; } const d = new Date(v); return isNaN(d.getTime()) ? '' : d.toLocaleString(idioma()); };
    const ITI = 'https://validar.iti.gov.br';

    /* As linhas e a nota, iguais no passo 3 e no detalhe (dc:188 e dc:218). */
    function miolo(q) {
        const linhas = (q.linhas || []).map(l => {
            const det = [dataHora(l.em) || J('data_nao_informada'), l.detalhe].filter(Boolean).join(' · ');
            return '<div style="display:flex; gap:9px; align-items:flex-start; font-size:13px;"><i class="bi ' + esc(l.icone) + '" style="color:' + esc(l.cor) + '; font-size:15px;"></i>'
                + '<div style="flex:1 1 auto; min-width:0;"><b>' + esc(l.nome) + '</b> <span style="color:#5b7383;">' + esc(l.documento || '') + '</span>'
                + '<div style="color:#34505f; line-height:1.4;">' + esc(det) + '</div></div></div>';
        }).join('');
        return '<b style="font-size:14.5px; color:#0b3550;"><i class="bi bi-patch-check-fill" style="color:' + esc(q.cor) + ';"></i> ' + esc(q.titulo) + '</b>'
            + linhas
            + '<div style="font-size:12.5px; color:#34505f; line-height:1.45;">' + esc(q.nota) + '</div>';
    }

    const caixa = (q, dentro) => '<div style="border:1px solid #bcd6e5; border-left:4px solid ' + esc(q.cor) + '; background:#f5fafd; padding:12px 14px; display:flex; flex-direction:column; gap:8px; margin-bottom:10px;">' + dentro + '</div>';

    /* Passo 3 (dc:188): só o quadro, sem botões. Sem `pres`, nada. */
    function quadro(q) {
        return q && (q.linhas || []).length ? caixa(q, miolo(q)) : '';
    }

    /* Detalhe (dc:218): o quadro, os botões e o aviso. `urlCarimbo` vazio esconde a cópia com carimbo. */
    function quadroDoDetalhe(d, urlCarimbo) {
        const q = d && d.jaAssinado;
        if (!q || !(q.linhas || []).length) { return ''; }
        const carimbo = !!(q.carimbo && urlCarimbo);
        const botoes = '<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">'
            + '<button type="button" class="bjaB" data-bja-acao="ja-original"><i class="bi bi-shield-lock"></i>' + esc(J('baixar_original')) + '</button>'
            + (carimbo ? '<button type="button" class="bjaB" data-bja-acao="ja-carimbo"><i class="bi bi-stamp"></i>' + esc(J('copia_carimbo')) + '</button>' : '')
            + '<a class="bjaB" href="' + ITI + '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>' + esc(J('conferir_iti')) + '</a></div>';
        const aviso = q.aviso ? '<div style="font-size:12.5px; color:#8a6200; line-height:1.45;">' + esc(q.aviso) + '</div>' : '';
        return caixa(q, miolo(q) + botoes + aviso);
    }

    /* "Baixar manifesto com selo" quando o documento já chegou assinado (dc:462); senão, o rótulo de sempre. */
    function rotuloDoBaixar(d) {
        if (d && d.jaAssinado) { return T('pdf.baixar_manifesto'); }
        return d && d.folhaBranca ? T('pdf.baixar_rotulo') : T('pdf.baixar');
    }

    /* Falha da conferência no passo 1: a recusa do arquivo (422) e a de acesso com texto (403) mostram o texto do
       servidor; limite (429), falha do servidor (5xx), rede caída ou 403 sem texto não dizem nada do arquivo, e um PDF
       bom não pode aparecer como arquivo ruim. */
    function textoDaFalha(e) {
        const texto = e && e.texto ? String(e.texto) : '';
        return texto && (e.status === 422 || e.status === 403) ? texto : J('conferir_indisponivel');
    }

    /* Confere o PDF do passo 1 sem gravar, pelo pedir() do assinatura.js. Toda falha sai como erro com o texto da tela
       em `texto` (textoDaFalha). */
    async function conferir(endereco, arquivo, pedir) {
        const fd = new FormData();
        fd.append('arquivo', arquivo, arquivo.name);
        let j = null;
        try {
            j = await pedir(endereco, { method: 'POST', body: fd });
        } catch (e) {
            const falha = new Error('conferência do PDF');
            falha.status = e && e.status;
            falha.texto = textoDaFalha(e);
            throw falha;
        }
        return j && j.jaAssinado ? j.jaAssinado : null;
    }

    /* salvaBytes do desenho: baixa com o nome dado e libera a URL depois de 4 s. */
    function salvar(blob, nome) {
        const u = URL.createObjectURL(blob), l = document.createElement('a');
        l.href = u;
        l.download = nome;
        document.body.appendChild(l);
        l.click();
        l.remove();
        setTimeout(() => URL.revokeObjectURL(u), 4000);
    }

    /* presOrig (dc:383): a última versão guardada, "{nome} - original.pdf". */
    async function baixarOriginal(d, pedir, avisar) {
        const q = d && d.jaAssinado;
        if (!q || !q.original) { return; }
        try {
            salvar(await pedir(q.original, { blob: true }), J('nome_original', { nome: d.nome }));
        } catch (e) {
            avisar(e && e.status === 404 ? J('original_nao_encontrado') : (e && e.texto) || T('erro_geral'), 'erro');
        }
    }

    /* presCarimbo (dc:384), contrato do 5B: 422 traz o motivo em `erro` e ganha " Use o original.", menos com
       `semAssinatura` (só o motivo); 404 traz "Original não encontrado." (só o texto); outra falha, o texto geral. */
    function avisoDoCarimbo(e) {
        const texto = e && e.texto ? String(e.texto) : '';
        if (!texto || (e.status !== 422 && e.status !== 404)) { return J('carimbo.falhou'); }
        return e.status === 422 && !(e.dados && e.dados.semAssinatura) ? texto + J('carimbo.use_original') : texto;
    }

    async function baixarCarimbo(d, endereco, pedir, avisar) {
        if (!d || !endereco) { return; }
        try {
            salvar(await pedir(endereco, { blob: true }), J('nome_carimbo', { nome: d.nome }));
        } catch (e) {
            avisar(avisoDoCarimbo(e), 'erro');
        }
    }

    window.BJAJaAssinado = { quadro, quadroDoDetalhe, rotuloDoBaixar, conferir, baixarOriginal, baixarCarimbo };
})();
