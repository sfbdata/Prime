/* =============================================================================
   assinatura-pdf.js: "Baixar PDF com selo" da Assinatura de documentos (etapa 3, inventário A5 e tela verde).
   Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html (pdf(d), dc:425: o arquivo baixado e,
   no fim, o aviso "PDF com selo gerado.").
   Contrato: docs/specs/assinatura-documentos-etapa-3.md (E3-4 e E3-5).

   O PDF é montado no servidor (GET /assinatura/documentos/{id}/pdf na tela interna, GET /assinar/{token}/pdf na tela
   do signatário). Aqui só se baixa: o arquivo chega inteiro, vira um link de download com o nome do servidor e quem
   chamou mostra o aviso. Um clique por URL de cada vez (a geração leva alguns segundos).
   Uso: BJAssinaPdf.baixar(url, { aoConcluir: () => ..., aoFalhar: erro => ... }).
   ============================================================================= */
(function () {
    'use strict';

    const emCurso = {};

    /* O nome do Content-Disposition: filename* (UTF-8) primeiro, senão filename. */
    function nomeDoArquivo(cabecalho, padrao) {
        const h = String(cabecalho || '');
        const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(h);
        if (utf8) {
            try { return decodeURIComponent(utf8[1]); } catch (e) { /* segue para o filename */ }
        }
        const simples = /filename="?([^";]+)"?/i.exec(h);
        return simples ? simples[1] : padrao;
    }

    function entregar(blob, nome) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = nome;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 60000);
    }

    async function baixar(url, opcoes) {
        const o = opcoes || {};
        if (!url || emCurso[url]) { return false; }
        emCurso[url] = true;
        try {
            const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } });
            if (!r.ok) { throw new Error('PDF com selo: HTTP ' + r.status); }
            entregar(await r.blob(), nomeDoArquivo(r.headers.get('Content-Disposition'), 'documento.pdf'));
            if (o.aoConcluir) { o.aoConcluir(); }
            return true;
        } catch (e) {
            if (o.aoFalhar) { o.aoFalhar(e); }
            return false;
        } finally {
            delete emCurso[url];
        }
    }

    window.BJAssinaPdf = { baixar: baixar };
})();
