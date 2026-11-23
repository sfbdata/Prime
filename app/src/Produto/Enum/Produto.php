<?php

declare(strict_types=1);

namespace App\Produto\Enum;

/**
 * Produtos que um escritório pode ter (docs/specs/multiproduto-contratacao-e-voto.md, §1 e §3).
 *
 * `Completo` não é uma ferramenta: é o escritório com tudo, como os de hoje. Os valores são os mesmos do
 * CHECK da tabela `contratacao` (§4).
 */
enum Produto: string
{
    case Completo = 'completo';
    case Voto = 'voto';
    case Expediente = 'expediente';
    case Ia = 'ia';
    case Ponto = 'ponto';
    case Calculo = 'calculo';
    case Kanban = 'kanban';
    case Assinatura = 'assinatura';
    case Cobrancas = 'cobrancas';

    /** @return list<self> as ferramentas, sem o `Completo`, na ordem em que o cartão do Meu Perfil as mostra. */
    public static function ferramentas(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => self::Completo !== $p));
    }
}
