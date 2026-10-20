<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

/**
 * O pedido de convite por e-mail. O formato do e-mail é conferido no UseCase, depois do cadastro e das travas, para
 * as mensagens saírem na ordem do desenho (bj-contas.js L130).
 */
final class IndicarPorEmailInput
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $nome = null,
    ) {
    }
}
