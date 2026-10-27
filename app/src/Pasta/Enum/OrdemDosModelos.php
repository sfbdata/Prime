<?php

declare(strict_types=1);

namespace App\Pasta\Enum;

/**
 * Ordenação da lista do banco de modelos (desenho 02 de 27/10). "Mais usados" é o padrão; no M1 o
 * contador de usos fica em 0 (quem usa é o M2), então o empate se desfaz pelo mais recente.
 */
enum OrdemDosModelos: string
{
    case MaisUsados = 'uso';
    case MaisRecentes = 'rec';
    case AaZ = 'az';

    public static function daRequisicao(?string $valor): self
    {
        return self::tryFrom((string) $valor) ?? self::MaisUsados;
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::MaisUsados => 'Mais usados',
            self::MaisRecentes => 'Mais recentes',
            self::AaZ => 'A a Z',
        };
    }
}
