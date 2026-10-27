<?php

declare(strict_types=1);

namespace App\Pagamento\DTO;

use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Enum\StatusDoPagamento;

/** O que o consumidor precisa saber da cobrança: sem a entidade, para o domínio dele não importar `Pagamento\Entity`. */
final class CobrancaPixOutput
{
    public function __construct(
        public readonly int $pagamentoId,
        public readonly string $referencia,
        public readonly StatusDoPagamento $status,
        public readonly int $valorCentavos,
        /** PIX copia e cola; nulo enquanto a cobrança não foi criada no banco. */
        public readonly ?string $brCode,
        public readonly ?\DateTimeImmutable $expiraEm,
        /** Quantas cobranças já existiram para o consumidor (a tentativa n da próxima referência é n + 1). */
        public readonly int $tentativas = 1,
    ) {
    }

    public static function fromEntity(Pagamento $pagamento, int $tentativas = 1): self
    {
        return new self(
            (int) $pagamento->getId(),
            $pagamento->getReferencia(),
            $pagamento->getStatus(),
            $pagamento->getValorCentavos(),
            $pagamento->getBrCode(),
            $pagamento->getExpiraEm(),
            $tentativas,
        );
    }

    /** Aguardando, com QR no banco e ainda dentro da validade. */
    public function viva(\DateTimeImmutable $agora): bool
    {
        return $this->status === StatusDoPagamento::Aguardando && $this->brCode !== null && ($this->expiraEm === null || $this->expiraEm > $agora);
    }
}
