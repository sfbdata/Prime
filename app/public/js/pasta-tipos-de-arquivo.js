/* BlueJus · Tipo do arquivo pela extensão (dc `expTipo`, L4675-4680): rótulo, ícone e cor.
   Saiu do pasta-explorador.js (03/11/2026) para o modal da lixeira (pasta-lixeira.js) ver o mesmo tipo
   também na Lixeira do Expediente, sem o explorador na página. O explorador lê daqui (`BJ_TiposDeArquivo`).
   O rótulo vem do catálogo `pasta_scripts.js.explorador.tipo_*` (a página precisa dele). */
(function () {
    'use strict';
    if (window.BJ_TiposDeArquivo) return;
    function extensaoDe(nome) {
        const m = String(nome || '').match(/\.([^.\/\\]+)$/);
        return m ? m[1] : '';
    }

    /* ---- Tipo pela extensão (dc `expTipo`, L4675-4680): rótulo, ícone e cor. ---- */
    const TIPO_ALIAS = { DOCM: 'DOCX', DOTX: 'DOCX', XLSM: 'XLSX', XLS: 'XLSX', XLSB: 'XLSX', JPEG: 'JPG', JFIF: 'JPG', WEBP: 'PNG', GIF: 'PNG', BMP: 'PNG', SVG: 'PNG' };
    const TIPOS = {
        PDF:  ['bi-file-earmark-pdf-fill',   'pdf',    BJ_T.t('pasta_scripts.js.explorador.tipo_pdf')],
        DOCX: ['bi-file-earmark-word-fill',  'word',   BJ_T.t('pasta_scripts.js.explorador.tipo_word')],
        DOC:  ['bi-file-earmark-word-fill',  'word',   BJ_T.t('pasta_scripts.js.explorador.tipo_word')],
        ODT:  ['bi-file-earmark-word-fill',  'word',   BJ_T.t('pasta_scripts.js.explorador.tipo_odt')],
        RTF:  ['bi-file-earmark-richtext-fill', 'word', BJ_T.t('pasta_scripts.js.explorador.tipo_rtf')],
        TXT:  ['bi-file-earmark-text-fill',  'txt',    BJ_T.t('pasta_scripts.js.explorador.tipo_texto')],
        XLSX: ['bi-file-earmark-excel-fill', 'excel',  BJ_T.t('pasta_scripts.js.explorador.tipo_excel')],
        CSV:  ['bi-filetype-csv',            'excel',  BJ_T.t('pasta_scripts.js.explorador.tipo_csv')],
        ODS:  ['bi-file-earmark-spreadsheet-fill', 'excel', BJ_T.t('pasta_scripts.js.explorador.tipo_ods')],
        PPTX: ['bi-file-earmark-slides-fill', 'slides', BJ_T.t('pasta_scripts.js.explorador.tipo_apresentacao')],
        PPT:  ['bi-file-earmark-slides-fill', 'slides', BJ_T.t('pasta_scripts.js.explorador.tipo_apresentacao')],
        ODP:  ['bi-file-earmark-slides-fill', 'slides', BJ_T.t('pasta_scripts.js.explorador.tipo_apresentacao')],
        ZIP:  ['bi-file-earmark-zip-fill',   'zip',    BJ_T.t('pasta_scripts.js.explorador.tipo_compactada')],
        RAR:  ['bi-file-earmark-zip-fill',   'zip',    BJ_T.t('pasta_scripts.js.explorador.tipo_compactada')],
        '7Z': ['bi-file-earmark-zip-fill',   'zip',    BJ_T.t('pasta_scripts.js.explorador.tipo_compactada')],
        JPG:  ['bi-file-earmark-image-fill', 'img',    BJ_T.t('pasta_scripts.js.explorador.tipo_jpeg')],
        PNG:  ['bi-file-earmark-image-fill', 'img',    BJ_T.t('pasta_scripts.js.explorador.tipo_png')],
        TIF:  ['bi-file-earmark-image-fill', 'img',    BJ_T.t('pasta_scripts.js.explorador.tipo_tiff')],
        TIFF: ['bi-file-earmark-image-fill', 'img',    BJ_T.t('pasta_scripts.js.explorador.tipo_tiff')],
        HEIC: ['bi-file-earmark-image-fill', 'img',    BJ_T.t('pasta_scripts.js.explorador.tipo_heic')],
        MP3:  ['bi-file-earmark-music-fill', 'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_audio')],
        WAV:  ['bi-file-earmark-music-fill', 'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_audio')],
        OGG:  ['bi-file-earmark-music-fill', 'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_audio')],
        OPUS: ['bi-file-earmark-music-fill', 'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_audio_whatsapp')],
        M4A:  ['bi-file-earmark-music-fill', 'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_audio')],
        MP4:  ['bi-file-earmark-play-fill',  'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_video')],
        MOV:  ['bi-file-earmark-play-fill',  'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_video')],
        WEBM: ['bi-file-earmark-play-fill',  'midia',  BJ_T.t('pasta_scripts.js.explorador.tipo_video')],
        EML:  ['bi-envelope-paper-fill',     'email',  BJ_T.t('pasta_scripts.js.explorador.tipo_email')],
        MSG:  ['bi-envelope-paper-fill',     'email',  BJ_T.t('pasta_scripts.js.explorador.tipo_email_outlook')],
        P7S:  ['bi-patch-check-fill',        'assin',  BJ_T.t('pasta_scripts.js.explorador.tipo_assinatura')],
        P7M:  ['bi-patch-check-fill',        'assin',  BJ_T.t('pasta_scripts.js.explorador.tipo_assinado')],
        JSON: ['bi-filetype-json',           'txt',    BJ_T.t('pasta_scripts.js.explorador.tipo_json')],
        XML:  ['bi-filetype-xml',            'txt',    BJ_T.t('pasta_scripts.js.explorador.tipo_xml')],
        HTML: ['bi-filetype-html',           'txt',    BJ_T.t('pasta_scripts.js.explorador.tipo_html')],
        KML:  ['bi-geo-alt-fill',            'txt',    BJ_T.t('pasta_scripts.js.explorador.tipo_kml')],
    };
    function tipoDe(nome) {
        const ext = extensaoDe(nome).toUpperCase();
        if (TIPOS[ext]) return TIPOS[ext];
        const alias = TIPO_ALIAS[ext];
        if (alias && TIPOS[alias]) { const b = TIPOS[alias]; return [b[0], b[1], BJ_T.t('pasta_scripts.js.explorador.tipo_com_extensao', { tipo: b[2], ext: ext })]; }
        return ['bi-file-earmark-fill', 'outro', ext ? BJ_T.t('pasta_scripts.js.explorador.tipo_arquivo_ext', { ext: ext }) : BJ_T.t('pasta_scripts.js.explorador.tipo_arquivo')];
    }
    const TIPO_PASTA = ['bi-folder-fill', 'pasta', BJ_T.t('pasta_scripts.js.explorador.tipo_pasta')];

    window.BJ_TiposDeArquivo = { extensaoDe: extensaoDe, tipoDe: tipoDe, TIPO_PASTA: TIPO_PASTA };
})();
