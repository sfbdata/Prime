/* =============================================================================
   assinatura-pdf.js: "Baixar PDF com selo" da Assinatura de documentos (etapa 3, inventário A5 e tela verde).
   Desenho: docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html (pdf(d), dc:425: o arquivo baixado e,
   no fim, o aviso "PDF com selo gerado.").
   Contrato: docs/specs/assinatura-documentos-etapa-3.md (E3-4 e E3-5).

   O PDF é montado no servidor (GET /assinatura/documentos/{id}/pdf na tela interna, GET /assinar/{token}/pdf na tela
   do signatário). Aqui só se baixa: o arquivo chega inteiro, vira um link de download com o nome do servidor e quem
   chamou mostra o aviso. Um clique por URL de cada vez (a geração leva cerca de 1,2 s por página): aoIniciar mostra
   "Gerando o PDF..." e o segundo clique durante a geração é ignorado.
   Falha: o erro leva `texto` quando o servidor respondeu `{"erro": ...}` (o 429 das vagas e do limitador); sem ele, quem
   chamou mostra "Não foi possível gerar o PDF.". O PDF que chega sem `%%EOF` no fim foi cortado no servidor (a geração
   falhou depois do início da resposta) e não é salvo.
   Uso: BJAssinaPdf.baixar(url, { aoIniciar: () => ..., aoConcluir: () => ..., aoFalhar: erro => ... }).
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

    /* O erro de uma resposta não 2xx, com o `texto` do `{"erro": ...}` quando o servidor mandou um. */
    async function erroDaResposta(r) {
        const e = new Error('PDF com selo: HTTP ' + r.status);
        if ((r.headers.get('Content-Type') || '').indexOf('json') >= 0) {
            const corpo = await r.json().catch(() => null);
            if (corpo && typeof corpo.erro === 'string') { e.texto = corpo.erro; }
        }
        return e;
    }

    /* Todo PDF inteiro termina em %%EOF (com o fim de linha depois); o cortado no meio da geração, não. */
    async function inteiro(blob) {
        const fim = await blob.slice(Math.max(0, blob.size - 1024)).text();
        return fim.indexOf('%%EOF') >= 0;
    }

    async function baixar(url, opcoes) {
        const o = opcoes || {};
        if (!url || emCurso[url]) { return false; }
        emCurso[url] = true;
        if (o.aoIniciar) { o.aoIniciar(); }
        try {
            const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } });
            if (!r.ok) { throw await erroDaResposta(r); }
            const blob = await r.blob();
            if (!(await inteiro(blob))) { throw new Error('PDF com selo cortado: sem %%EOF no fim'); }
            entregar(blob, nomeDoArquivo(r.headers.get('Content-Disposition'), 'documento.pdf'));
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
