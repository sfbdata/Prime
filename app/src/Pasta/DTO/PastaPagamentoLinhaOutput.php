<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaPagamento;

/**
 * Uma linha do card Pagamentos, já pronta para impressão — a tela não decide
 * estado nem formata dinheiro.
 */
final readonly class PastaPagamentoLinhaOutput
{
    public const ESTADO_PAGO     = 'Pago';
    public const ESTADO_PENDENTE = 'Pendente';

    public function __construct(
        public int $id,
        public string $descricao,
        public string $valorFormatado,
        public string $quando,
        public string $estado,
        /** `ok` (verde) ou `proximo` (âmbar) — os tons de selo que a tela já tem. */
        public string $tom,
        public bool $pago,
    ) {}

    /**
     * Dois selos, como o desenho aprovado mostra: Pendente (âmbar) e Pago
     * (verde). O ATRASO não vira um terceiro selo — ele aparece na linha de
     * apoio ("atrasado 2 dias"), que é onde o desenho já põe o tempo. Pintar o
     * vencido de vermelho é uma proposta em aberto, não uma entrega.
     */
    public static function montar(PastaPagamento $pagamento, \DateTimeImmutable $hoje): self
    {
        $pago = $pagamento->estaPago();

        return new self(
            id: (int) $pagamento->getId(),
            descricao: $pagamento->getDescricao(),
            valorFormatado: PastaFinanceiroOutput::formatarReais($pagamento->getValor()),
            quando: self::quando($pagamento, $hoje),
            estado: $pago ? self::ESTADO_PAGO : self::ESTADO_PENDENTE,
            tom: $pago ? 'ok' : 'proximo',
            pago: $pago,
        );
    }

    /**
     * A linha de apoio diz a data e, quando ela ainda importa, a distância até
     * lá. Pagamento quitado não fala mais de vencimento: fala de quando entrou.
     */
    private static function quando(PastaPagamento $pagamento, \DateTimeImmutable $hoje): string
    {
        if ($pagamento->estaPago()) {
            return 'pago em ' . $pagamento->getPagoEm()?->format('d/m/Y');
        }

        $vencimento = $pagamento->getVencimento();
        $dias       = (int) $hoje->diff($vencimento)->format('%r%a');

        $texto = 'vence ' . $vencimento->format('d/m/Y');

        return match (true) {
            $pagamento->estaVencido($hoje) => $texto . ' · atrasado ' . abs($dias) . (abs($dias) === 1 ? ' dia' : ' dias'),
            $dias === 0 => $texto . ' · hoje',
            $dias === 1 => $texto . ' · amanhã',
            $dias <= 30 => $texto . ' · em ' . $dias . ' dias',
            default     => $texto,
        };
    }
}
