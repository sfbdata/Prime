<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Estado civil da Pessoa cobrada (SPEC qualificação §3). Campo ÚNICO na ficha — decisão do dono:
 * ao contrário de endereço/telefone/e-mail, não vira histórico/lista.
 */
enum EstadoCivil: string
{
    case Solteiro = 'solteiro';
    case Casado = 'casado';
    case Divorciado = 'divorciado';
    case Viuvo = 'viuvo';
    case UniaoEstavel = 'uniao_estavel';
    case Separado = 'separado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Solteiro => 'Solteiro(a)',
            self::Casado => 'Casado(a)',
            self::Divorciado => 'Divorciado(a)',
            self::Viuvo => 'Viúvo(a)',
            self::UniaoEstavel => 'União estável',
            self::Separado => 'Separado(a)',
        };
    }
}
