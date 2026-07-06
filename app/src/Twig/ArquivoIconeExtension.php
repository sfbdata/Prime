<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Ícone/cor por tipo de arquivo (a partir da extensão do nome, com fallback no
 * MIME) e formatação de tamanho. Usado pelo gerenciador de arquivos da pasta.
 * As cores são fixas por tipo (convenção de file manager) e legíveis em tema
 * claro e escuro.
 */
final class ArquivoIconeExtension extends AbstractExtension
{
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
     * @return array{icone: string, cor: string, rotulo: string}
     */
    public function arquivoIcone(string $nome, string $mime = ''): array
    {
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));

        $mapa = [
            // documentos
            'pdf'  => ['bi-filetype-pdf',  '#e2564d', 'PDF'],
            'doc'  => ['bi-filetype-doc',  '#2b7cd3', 'Word'],
            'docx' => ['bi-filetype-docx', '#2b7cd3', 'Word'],
            'odt'  => ['bi-filetype-doc',  '#2b7cd3', 'Documento'],
            'rtf'  => ['bi-filetype-doc',  '#2b7cd3', 'Documento'],
            'txt'  => ['bi-filetype-txt',  '#6c757d', 'Texto'],
            'md'   => ['bi-filetype-md',   '#6c757d', 'Texto'],
            // planilhas
            'xls'  => ['bi-filetype-xls',  '#1f9d55', 'Planilha'],
            'xlsx' => ['bi-filetype-xlsx', '#1f9d55', 'Planilha'],
            'ods'  => ['bi-filetype-xlsx', '#1f9d55', 'Planilha'],
            'csv'  => ['bi-filetype-csv',  '#1f9d55', 'CSV'],
            // apresentações
            'ppt'  => ['bi-filetype-ppt',  '#d9822b', 'Slides'],
            'pptx' => ['bi-filetype-pptx', '#d9822b', 'Slides'],
            'odp'  => ['bi-filetype-pptx', '#d9822b', 'Slides'],
            // imagens
            'png'  => ['bi-filetype-png',  '#7b5ea7', 'Imagem'],
            'jpg'  => ['bi-filetype-jpg',  '#7b5ea7', 'Imagem'],
            'jpeg' => ['bi-filetype-jpg',  '#7b5ea7', 'Imagem'],
            'gif'  => ['bi-filetype-gif',  '#7b5ea7', 'Imagem'],
            'webp' => ['bi-file-image',    '#7b5ea7', 'Imagem'],
            'bmp'  => ['bi-file-image',    '#7b5ea7', 'Imagem'],
            'svg'  => ['bi-filetype-svg',  '#7b5ea7', 'Imagem'],
            'heic' => ['bi-file-image',    '#7b5ea7', 'Imagem'],
            // compactados
            'zip'  => ['bi-file-zip',      '#b08900', 'Compactado'],
            'rar'  => ['bi-file-zip',      '#b08900', 'Compactado'],
            '7z'   => ['bi-file-zip',      '#b08900', 'Compactado'],
            'gz'   => ['bi-file-zip',      '#b08900', 'Compactado'],
            'tar'  => ['bi-file-zip',      '#b08900', 'Compactado'],
            // mídia
            'mp3'  => ['bi-filetype-mp3',  '#c2410c', 'Áudio'],
            'wav'  => ['bi-filetype-wav',  '#c2410c', 'Áudio'],
            'ogg'  => ['bi-file-music',    '#c2410c', 'Áudio'],
            'mp4'  => ['bi-filetype-mp4',  '#be123c', 'Vídeo'],
            'mov'  => ['bi-filetype-mov',  '#be123c', 'Vídeo'],
            'avi'  => ['bi-file-play',     '#be123c', 'Vídeo'],
            'mkv'  => ['bi-file-play',     '#be123c', 'Vídeo'],
            // web/código
            'html' => ['bi-filetype-html', '#e34c26', 'HTML'],
            'xml'  => ['bi-filetype-xml',  '#6c757d', 'XML'],
            'json' => ['bi-filetype-json', '#6c757d', 'JSON'],
        ];

        if ($ext !== '' && isset($mapa[$ext])) {
            [$icone, $cor, $rotulo] = $mapa[$ext];

            return ['icone' => $icone, 'cor' => $cor, 'rotulo' => $rotulo];
        }

        // Fallback pelo MIME quando a extensão não ajuda.
        if (str_starts_with($mime, 'image/')) {
            return ['icone' => 'bi-file-image', 'cor' => '#7b5ea7', 'rotulo' => 'Imagem'];
        }
        if (str_starts_with($mime, 'video/')) {
            return ['icone' => 'bi-file-play', 'cor' => '#be123c', 'rotulo' => 'Vídeo'];
        }
        if (str_starts_with($mime, 'audio/')) {
            return ['icone' => 'bi-file-music', 'cor' => '#c2410c', 'rotulo' => 'Áudio'];
        }
        if (str_contains($mime, 'pdf')) {
            return ['icone' => 'bi-filetype-pdf', 'cor' => '#e2564d', 'rotulo' => 'PDF'];
        }
        if (str_contains($mime, 'word') || str_contains($mime, 'document')) {
            return ['icone' => 'bi-filetype-doc', 'cor' => '#2b7cd3', 'rotulo' => 'Word'];
        }
        if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) {
            return ['icone' => 'bi-filetype-xlsx', 'cor' => '#1f9d55', 'rotulo' => 'Planilha'];
        }
        if (str_contains($mime, 'zip') || str_contains($mime, 'compress')) {
            return ['icone' => 'bi-file-zip', 'cor' => '#b08900', 'rotulo' => 'Compactado'];
        }

        return ['icone' => 'bi-file-earmark', 'cor' => '#6c757d', 'rotulo' => 'Arquivo'];
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
}
