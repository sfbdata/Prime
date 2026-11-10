<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;

/**
 * As fontes do PDF (E3-15): Arial do desenho = NimbusSans; Courier = NimbusMonoPS, do pacote `fonts-urw-base35`.
 */
enum Fonte: string
{
    private const PASTA = '/usr/share/fonts/opentype/urw-base35/';

    case Sans = 'NimbusSans-Regular.otf';
    case SansNegrito = 'NimbusSans-Bold.otf';
    case Mono = 'NimbusMonoPS-Regular.otf';
    case MonoNegrito = 'NimbusMonoPS-Bold.otf';

    public static function arial(bool $negrito): self
    {
        return $negrito ? self::SansNegrito : self::Sans;
    }

    /**
     * @throws FalhaAoMontarPdf quando a fonte não está instalada (o pacote saiu do Dockerfile)
     */
    public function caminho(): string
    {
        static $conferidas = [];
        $caminho = self::PASTA . $this->value;

        if (!isset($conferidas[$caminho])) {
            if (!is_readable($caminho)) {
                throw new FalhaAoMontarPdf(sprintf('Fonte do PDF não encontrada: %s (pacote fonts-urw-base35).', $caminho));
            }
            $conferidas[$caminho] = true;
        }

        return $caminho;
    }
}
