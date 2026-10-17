<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Ícone/cor por tipo de arquivo (a partir da extensão do nome, com fallback no
 * MIME) e formatação de tamanho. Fonte ÚNICA do padrão no Twig: trilho de Dados e
 * Financeiro da pasta, documentos da carteira e do caso na Cobrança.
 *
 * O padrão é o do Guia visual BlueJus (04 Tipografia e ícones): família
 * `bi-file-earmark-*-fill`, cor só para o tipo de arquivo. É a MESMA família,
 * o MESMO `tipo` e a MESMA paleta que a aba Documentos desenha no navegador
 * (pasta-explorador.js `TIPOS`/`TIPO_ALIAS` + tokens `--pex-c-*`).
 *
 * `cor` é o hex do tema claro, para quem pinta inline (Cobrança). Na tela da
 * pasta a cor sai por classe `ps-doc-icone--<tipo>` lendo os tokens
 * `--ps-arquivo-<tipo>` do pasta-show.css, que têm par escuro igual ao do
 * explorador; o espelho JS do Financeiro (`iconeDoArquivo` em
 * pasta/_financeiro.html.twig) repete ícone, tipo e rótulo. Mudou em um lugar,
 * muda nos outros: ArquivoIconePadraoFolhaTest compara helper × explorador ×
 * pasta-show.css e PastaFinanceiroIconeEspelhoFolhaTest compara helper × espelho.
 */
final class ArquivoIconeExtension extends AbstractExtension
{
    /**
     * Cor por TIPO (hex do tema claro): PDF, Word e Excel são os do Guia visual; os
     * demais, os tokens claros `--pex-c-*` do explorador. As chaves são as mesmas
     * classes `pex-ico-<tipo>` do explorador e `ps-doc-icone--<tipo>` da pasta.
     *
     * @var array<string, string>
     */
    private const CORES = [
        'pdf'    => '#d93a2b',
        'word'   => '#1f5fbf',
        'excel'  => '#1d7a46',
        'img'    => '#7a4bb0',
        'zip'    => '#b7791f',
        'txt'    => '#4f6878',
        'slides' => '#c0571d',
        'midia'  => '#7a4bb0',
        'email'  => '#0a7aad',
        'assin'  => '#1d7a46',
        'outro'  => '#6b8494',
    ];

    /** @var array<string, array{0: string, 1: string, 2: string}> extensão => [ícone, tipo, rótulo] */
    private const MAPA = [
        // documentos
        'pdf'  => ['bi-file-earmark-pdf-fill',      'pdf',   'PDF'],
        'doc'  => ['bi-file-earmark-word-fill',     'word',  'Word'],
        'docx' => ['bi-file-earmark-word-fill',     'word',  'Word'],
        'docm' => ['bi-file-earmark-word-fill',     'word',  'Word'],
        'dotx' => ['bi-file-earmark-word-fill',     'word',  'Word'],
        'odt'  => ['bi-file-earmark-word-fill',     'word',  'Documento'],
        'rtf'  => ['bi-file-earmark-richtext-fill', 'word',  'Documento'],
        'txt'  => ['bi-file-earmark-text-fill',     'txt', 'Texto'],
        'md'   => ['bi-file-earmark-text-fill',     'txt', 'Texto'],
        // planilhas
        'xls'  => ['bi-file-earmark-excel-fill',       'excel', 'Planilha'],
        'xlsx' => ['bi-file-earmark-excel-fill',       'excel', 'Planilha'],
        'xlsm' => ['bi-file-earmark-excel-fill',       'excel', 'Planilha'],
        'xlsb' => ['bi-file-earmark-excel-fill',       'excel', 'Planilha'],
        'ods'  => ['bi-file-earmark-spreadsheet-fill', 'excel', 'Planilha'],
        'csv'  => ['bi-filetype-csv',                  'excel', 'CSV'],
        // apresentações
        'ppt'  => ['bi-file-earmark-slides-fill', 'slides', 'Slides'],
        'pptx' => ['bi-file-earmark-slides-fill', 'slides', 'Slides'],
        'odp'  => ['bi-file-earmark-slides-fill', 'slides', 'Slides'],
        // imagens
        'png'  => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'jpg'  => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'jpeg' => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'jfif' => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'gif'  => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'webp' => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'bmp'  => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'svg'  => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'heic' => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'tif'  => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        'tiff' => ['bi-file-earmark-image-fill', 'img', 'Imagem'],
        // compactados
        'zip'  => ['bi-file-earmark-zip-fill', 'zip', 'Compactado'],
        'rar'  => ['bi-file-earmark-zip-fill', 'zip', 'Compactado'],
        '7z'   => ['bi-file-earmark-zip-fill', 'zip', 'Compactado'],
        'gz'   => ['bi-file-earmark-zip-fill', 'zip', 'Compactado'],
        'tar'  => ['bi-file-earmark-zip-fill', 'zip', 'Compactado'],
        // mídia
        'mp3'  => ['bi-file-earmark-music-fill', 'midia', 'Áudio'],
        'wav'  => ['bi-file-earmark-music-fill', 'midia', 'Áudio'],
        'ogg'  => ['bi-file-earmark-music-fill', 'midia', 'Áudio'],
        'opus' => ['bi-file-earmark-music-fill', 'midia', 'Áudio'],
        'm4a'  => ['bi-file-earmark-music-fill', 'midia', 'Áudio'],
        'mp4'  => ['bi-file-earmark-play-fill',  'midia', 'Vídeo'],
        'mov'  => ['bi-file-earmark-play-fill',  'midia', 'Vídeo'],
        'avi'  => ['bi-file-earmark-play-fill',  'midia', 'Vídeo'],
        'mkv'  => ['bi-file-earmark-play-fill',  'midia', 'Vídeo'],
        'webm' => ['bi-file-earmark-play-fill',  'midia', 'Vídeo'],
        // e-mail e assinatura digital
        'eml'  => ['bi-envelope-paper-fill', 'email',      'E-mail'],
        'msg'  => ['bi-envelope-paper-fill', 'email',      'E-mail'],
        'p7s'  => ['bi-patch-check-fill',    'assin', 'Assinatura'],
        'p7m'  => ['bi-patch-check-fill',    'assin', 'Assinatura'],
        // web/código
        'html' => ['bi-filetype-html', 'txt', 'HTML'],
        'xml'  => ['bi-filetype-xml',  'txt', 'XML'],
        'json' => ['bi-filetype-json', 'txt', 'JSON'],
        'kml'  => ['bi-geo-alt-fill',  'txt', 'Mapa'],
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('arquivo_icone', $this->arquivoIcone(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('formatar_bytes', $this->formatarBytes(...)),
        ];
    }

    /**
     * @return array{icone: string, tipo: string, cor: string, rotulo: string}
     */
    public function arquivoIcone(string $nome, string $mime = ''): array
    {
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));

        if ($ext !== '' && isset(self::MAPA[$ext])) {
            return self::montar(self::MAPA[$ext]);
        }

        // Fallback pelo MIME quando a extensão não ajuda.
        if (str_starts_with($mime, 'image/')) {
            return self::montar(['bi-file-earmark-image-fill', 'img', 'Imagem']);
        }
        if (str_starts_with($mime, 'video/')) {
            return self::montar(['bi-file-earmark-play-fill', 'midia', 'Vídeo']);
        }
        if (str_starts_with($mime, 'audio/')) {
            return self::montar(['bi-file-earmark-music-fill', 'midia', 'Áudio']);
        }
        if (str_contains($mime, 'pdf')) {
            return self::montar(['bi-file-earmark-pdf-fill', 'pdf', 'PDF']);
        }
        // Planilha e slides ANTES de Word: os MIMEs do Office trazem "officedocument"
        // no nome, e a checagem por "document" os engoliria como Word.
        if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) {
            return self::montar(['bi-file-earmark-excel-fill', 'excel', 'Planilha']);
        }
        if (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) {
            return self::montar(['bi-file-earmark-slides-fill', 'slides', 'Slides']);
        }
        if (str_contains($mime, 'word') || str_contains($mime, 'document')) {
            return self::montar(['bi-file-earmark-word-fill', 'word', 'Word']);
        }
        if (str_contains($mime, 'zip') || str_contains($mime, 'compress')) {
            return self::montar(['bi-file-earmark-zip-fill', 'zip', 'Compactado']);
        }

        return self::montar(['bi-file-earmark-fill', 'outro', 'Arquivo']);
    }

    public function formatarBytes(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);

        if ($bytes < 1024) {
            return number_format($bytes, 0, ',', '.') . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024 / 1024 / 1024, 2, ',', '.') . ' GB';
    }

    /**
     * @param array{0: string, 1: string, 2: string} $entrada [ícone, tipo, rótulo]
     *
     * @return array{icone: string, tipo: string, cor: string, rotulo: string}
     */
    private static function montar(array $entrada): array
    {
        [$icone, $tipo, $rotulo] = $entrada;

        return ['icone' => $icone, 'tipo' => $tipo, 'cor' => self::CORES[$tipo], 'rotulo' => $rotulo];
    }
}
