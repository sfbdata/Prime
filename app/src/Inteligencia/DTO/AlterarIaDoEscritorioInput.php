<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Uma linha da tabela "Escritórios" da aba do Master: liberar ou bloquear, o modo de cobrança e o orçamento mensal (em
 * centavos de dólar; nulo = sem orçamento). A entidade recusa de novo o orçamento fora da faixa.
 */
final class AlterarIaDoEscritorioInput
{
    public function __construct(
        #[Assert\Positive(message: 'Escritório inválido.')]
        public readonly int $tenantId,
        public readonly bool $liberada,
        #[Assert\Choice(callback: [self::class, 'modos'], message: 'Escolha um modo de cobrança da lista.')]
        public readonly string $modoDeCobranca,
        #[Assert\Range(
            notInRangeMessage: 'O orçamento mensal precisa ficar entre US$ 0,00 e US$ 100.000,00.',
            min: 0,
            max: ConfiguracaoDeInteligencia::ORCAMENTO_MAXIMO_CENTAVOS,
        )]
        public readonly ?int $orcamentoMensalCentavos,
    ) {
    }

    /** @return list<string> */
    public static function modos(): array
    {
        return array_map(static fn (ModoDeCobrancaDaIa $m): string => $m->value, ModoDeCobrancaDaIa::cases());
    }
}
