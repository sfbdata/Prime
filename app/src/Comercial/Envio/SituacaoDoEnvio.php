<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

/** A situação de um envio, para o histórico de um consumidor. Sem corpo nem cópia: esses o consumidor guarda. */
final class SituacaoDoEnvio
{
    public function __construct(
        public readonly int $envioId,
        /** enfileirado | enviando | enviado | falhou | bloqueado_descadastro | cancelado | incerto (só campanha, §8.1.5 e §8.5) */
        public readonly string $situacao,
        public readonly ?\DateTimeImmutable $enviadoEm,
        public readonly bool $abriu,
        public readonly bool $clicou,
    ) {
    }
}
