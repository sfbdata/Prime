<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Categoria de um documento anexado à Carteira de Cobrança (Ajuste #5): atas de assembleia,
 * contratos e outros documentos guardados no nível da carteira (não do caso). Classificação
 * leve para organização/filtro; não altera regra de negócio. `Outro` é o default quando o
 * gestor não classifica.
 */
enum CategoriaDocumentoCarteira: string
{
    case AtaDeReuniao = 'ata_de_reuniao';
    case Contrato = 'contrato';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::AtaDeReuniao => 'Ata de reunião',
            self::Contrato => 'Contrato',
            self::Outro => 'Outro',
        };
    }
}
