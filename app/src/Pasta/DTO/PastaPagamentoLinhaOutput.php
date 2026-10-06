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
    public const ESTADO_VENCIDA  = 'Vencida';

    public function __construct(
        public int $id,
        public string $descricao,
        public string $valorFormatado,
        public string $quando,
        public string $estado,
        /** `ok` (verde), `proximo` (âmbar) ou `urgente` (vermelho) — os tons de selo que a tela já tem. */
        public string $tom,
        public bool $pago,
    ) {}

    /**
     * Três selos, como o desenho 1.2.3 mostra (dc 3438-3441): Pago (verde),
     * Pendente (âmbar) e Vencida (vermelho). Vencida é só APRESENTAÇÃO: o estado
     * continua derivado da data de pagamento e do vencimento (`estaVencido`),
     * nada é gravado. O selo segue sendo o botão de quitar nos três casos.
     */
    public static function montar(PastaPagamento $pagamento, \DateTimeImmutable $hoje): self
    {
        $pago    = $pagamento->estaPago();
        $vencido = $pagamento->estaVencido($hoje);

        [$estado, $tom] = match (true) {
            $pago    => [self::ESTADO_PAGO, 'ok'],
            $vencido => [self::ESTADO_VENCIDA, 'urgente'],
            default  => [self::ESTADO_PENDENTE, 'proximo'],
        };

        return new self(
            id: (int) $pagamento->getId(),
            descricao: $pagamento->getDescricao(),
            valorFormatado: PastaFinanceiroOutput::formatarReais($pagamento->getValor()),
            quando: self::quando($pagamento, $hoje),
            estado: $estado,
            tom: $tom,
            pago: $pago,
        );
    }

    /**
     * A linha de apoio diz a data e, quando ela ainda importa, a distância até
     * lá — nos termos do desenho (dc 3440-3441): "venceu … · há N dias" para o
     * vencido; "· hoje" e "· em N dia(s)" até 7 dias; além disso, só a data.
     * Pagamento quitado não fala mais de vencimento: fala de quando entrou.
     */
    private static function quando(PastaPagamento $pagamento, \DateTimeImmutable $hoje): string
    {
        if ($pagamento->estaPago()) {
            return 'pago em ' . $pagamento->getPagoEm()?->format('d/m/Y');
        }

        $vencimento = $pagamento->getVencimento();
        $dias       = (int) $hoje->diff($vencimento)->format('%r%a');
        $data       = $vencimento->format('d/m/Y');

        if ($pagamento->estaVencido($hoje)) {
            $atraso = abs($dias);

            return 'venceu ' . $data . ' · há ' . $atraso . ($atraso === 1 ? ' dia' : ' dias');
        }

        return 'vence ' . $data . match (true) {
            $dias === 0 => ' · hoje',
            $dias <= 7  => ' · em ' . $dias . ($dias === 1 ? ' dia' : ' dias'),
            default     => '',
        };
    }
}
