<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Entity\Tenant\Tenant;

/**
 * O que a confirmação acabou de gravar, para a página da rota pública dizer o que a pessoa vai receber e oferecer o
 * link de descadastro da mesma lista e escopo. O endereço NÃO vai para a página: serve só ao `gerarLink`.
 */
final class ConfirmacaoRealizada
{
    public function __construct(
        public readonly string $lista,
        public readonly string $email,
        public readonly ?Tenant $escopo,
    ) {
    }
}
