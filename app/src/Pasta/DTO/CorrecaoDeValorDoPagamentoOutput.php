<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma correção de valor de um pagamento da pasta, lida do `audit_log`.
 *
 * Não há tabela de histórico: `PastaPagamento` é `Auditavel`, e o
 * `AuditLogSubscriber` já grava de/para, quem e quando de toda mudança de
 * `valor`. Uma segunda tabela só para isto seria uma segunda verdade — e as
 * duas começariam a discordar na primeira correção feita por outro caminho.
 *
 * Desenho 1.2.3 (dc 3462): cada correção vira "dd/mm/aaaa hh:mm · Nome:
 * R$ a → R$ b" no título do "corrigido · era R$ a" da linha.
 */
final readonly class CorrecaoDeValorDoPagamentoOutput
{
    public function __construct(
        public \DateTimeImmutable $em,
        /** Nome de quem corrigiu; o e-mail só quando o nome não existe mais. */
        public string $autor,
        /** Decimal de `decimal(15,2)`, como saiu do audit_log ("1300.00"). */
        public string $de,
        public string $para,
    ) {}

    public function linha(): string
    {
        return sprintf(
            '%s · %s: %s → %s',
            $this->em->format('d/m/Y H:i'),
            $this->autor,
            PastaFinanceiroOutput::formatarReais($this->de),
            PastaFinanceiroOutput::formatarReais($this->para),
        );
    }
}
