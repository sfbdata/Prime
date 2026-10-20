<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Pasta\Entity\PastaDocumento;

/**
 * Uma linha da lista da aba Documentos do drawer (spec inteligencia-drawer-da-pasta, F4): só
 * metadados, já prontos para a tela. Nem o caminho no armazenamento nem o conteúdo saem daqui.
 *
 * `extensao` é a do nome do arquivo, em maiúsculas (vazia se não houver): é ela que escolhe o ícone
 * do arquivo no desenho (`fi`, dc L3216-3230). `dados` é a linha "PDF · 1,2 MB · 12/10/2026".
 */
final readonly class DocumentoDoIndiceOutput
{
    private const ICONES = [
        'PDF' => ['bi-file-earmark-pdf-fill', 'pdf'],
        'DOC' => ['bi-file-earmark-word-fill', 'word'],
        'DOCX' => ['bi-file-earmark-word-fill', 'word'],
        'XLS' => ['bi-file-earmark-excel-fill', 'excel'],
        'XLSX' => ['bi-file-earmark-excel-fill', 'excel'],
        'CSV' => ['bi-file-earmark-excel-fill', 'excel'],
        'PPTX' => ['bi-file-earmark-ppt-fill', 'ppt'],
        'ZIP' => ['bi-file-earmark-zip-fill', 'zip'],
        'RAR' => ['bi-file-earmark-zip-fill', 'rar'],
        'PNG' => ['bi-file-earmark-image-fill', 'imagem'],
        'JPG' => ['bi-file-earmark-image-fill', 'imagem'],
        'JPEG' => ['bi-file-earmark-image-fill', 'imagem'],
        'TXT' => ['bi-file-earmark-text-fill', 'texto'],
        'MP4' => ['bi-file-earmark-play-fill', 'video'],
    ];

    public function __construct(
        public int $id,
        public string $nome,
        public string $extensao,
        public string $tipo,
        public string $tamanho,
        public string $data,
    ) {
    }

    public static function fromEntity(PastaDocumento $documento): self
    {
        $extensao = mb_strtoupper(pathinfo($documento->getNomeOriginal(), PATHINFO_EXTENSION));

        return new self(
            id: (int) $documento->getId(),
            nome: $documento->getNomeOriginal() !== '' ? $documento->getNomeOriginal() : $documento->getTitulo(),
            extensao: $extensao,
            tipo: $extensao !== '' ? $extensao : 'Arquivo',
            tamanho: self::tamanhoLegivel($documento->getTamanhoBytes()),
            data: $documento->getCarregadoEm()->format('d/m/Y'),
        );
    }

    /** "240 KB", "1,2 MB": vírgula decimal, como o desenho. */
    public static function tamanhoLegivel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 1, ',', '.') . ' GB';
    }

    /** Linha de dados do desenho: tipo · tamanho · data. */
    public function dados(): string
    {
        return $this->tipo . ' · ' . $this->tamanho . ' · ' . $this->data;
    }

    /** Ícone Bootstrap do arquivo pelo tipo (desenho, ícone pequeno de `fi`); desconhecido: a folha genérica. */
    public function icone(): string
    {
        return self::ICONES[$this->extensao][0] ?? 'bi-file-earmark-fill';
    }

    /** Família de cor do ícone (classe `ia-doc-ico--<familia>` no CSS, cores do `Component.FI` do desenho). */
    public function familia(): string
    {
        return self::ICONES[$this->extensao][1] ?? 'outro';
    }
}
