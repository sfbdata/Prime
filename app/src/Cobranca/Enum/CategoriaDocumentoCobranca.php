<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Categoria de um documento do Caso de Cobrança (SPEC §15). Classificação leve para organização/filtro;
 * não altera regra de negócio. `Outro` é o default quando o gestor não classifica.
 */
enum CategoriaDocumentoCobranca: string
{
    case TermoAcordo = 'termo_acordo';
    case Boleto = 'boleto';
    case Comprovante = 'comprovante';
    case Notificacao = 'notificacao';
    case Negociacao = 'negociacao';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::TermoAcordo => 'Termo de acordo',
            self::Boleto => 'Boleto',
            self::Comprovante => 'Comprovante',
            self::Notificacao => 'Notificação',
            self::Negociacao => 'Documento de negociação',
            self::Outro => 'Outro',
        };
    }
}
