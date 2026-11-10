<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\Shared\Service\OrcamentoDeMemoriaDeImagem;

/**
 * As imagens de uma montagem: a logo e as rubricas decodificadas uma vez (pequenas, ficam até o fim) e as páginas do
 * PDF enviado, rasterizadas uma por vez (quem pede libera). {@see liberar()} solta tudo no fim da montagem.
 */
final class RecursosDaFolha
{
    private ?\GdImage $logo = null;
    private bool $logoLida = false;
    /** @var array<int, \GdImage|null> */
    private array $rubricas = [];

    public function __construct(
        public readonly FolhaParaMontar $folha,
        private readonly RasterDoPdfEnviado $raster,
    ) {
    }

    /** A logo do documento; nula (ou ilegível) = marca "BlueJus" em texto. */
    public function logo(): ?\GdImage
    {
        if (!$this->logoLida) {
            $this->logoLida = true;
            $this->logo = self::decodificar($this->folha->logo);
        }

        return $this->logo;
    }

    /** A rubrica do signatário de índice $indice, se ele assinou e ela é legível. */
    public function rubrica(int $indice): ?\GdImage
    {
        if (!\array_key_exists($indice, $this->rubricas)) {
            $signatario = $this->folha->signatario($indice);
            $this->rubricas[$indice] = $signatario !== null && $signatario->assinou()
                ? self::decodificar($signatario->rubrica)
                : null;
        }

        return $this->rubricas[$indice];
    }

    /** A página $numero do PDF enviado; quem recebe libera com `imagedestroy`. */
    public function paginaDoPdfEnviado(int $numero): \GdImage
    {
        return $this->raster->pagina($numero);
    }

    public function liberar(): void
    {
        foreach (array_filter([$this->logo, ...$this->rubricas]) as $imagem) {
            imagedestroy($imagem);
        }

        $this->logo = null;
        $this->logoLida = false;
        $this->rubricas = [];
        $this->raster->liberar();
    }

    private static function decodificar(?string $bytes): ?\GdImage
    {
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $medidas = @getimagesizefromstring($bytes);
        if ($medidas === false || !OrcamentoDeMemoriaDeImagem::cabe($medidas[0], $medidas[1])) {
            return null;
        }

        $imagem = @imagecreatefromstring($bytes);

        return $imagem === false ? null : $imagem;
    }
}
