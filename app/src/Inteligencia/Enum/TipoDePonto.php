<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Tipos de ponto que o modelo pode devolver no resumo do Push (vocabulário do Designer). O que vier
 * fora da lista vira `Info` — nunca se inventa categoria a partir da resposta.
 */
enum TipoDePonto: string
{
    case Prazo = 'prazo';
    case Atencao = 'atencao';
    case Providencia = 'providencia';
    case Info = 'info';
    case Ok = 'ok';

    public static function deString(mixed $valor): self
    {
        if (!is_string($valor)) {
            return self::Info;
        }

        return self::tryFrom(mb_strtolower(trim($valor))) ?? self::Info;
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::Prazo => 'Prazo',
            self::Atencao => 'Atenção',
            self::Providencia => 'Providência',
            self::Info => 'Informação',
            self::Ok => 'Sem providência',
        };
    }
}
