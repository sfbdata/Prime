<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Categoria de um documento anexado ao Acordo (Ajuste #4): termo de acordo, contrato e outros
 * documentos guardados no nível do acordo (não do caso). Classificação leve para
 * organização/filtro; não altera regra de negócio. `Outro` é o default quando o gestor não
 * classifica.
 */
enum CategoriaDocumentoAcordo: string
{
    case TermoDeAcordo = 'termo_de_acordo';
    case Contrato = 'contrato';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::TermoDeAcordo => 'Termo de acordo',
            self::Contrato => 'Contrato',
            self::Outro => 'Outro',
        };
    }
}
