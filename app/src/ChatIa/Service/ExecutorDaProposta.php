<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\DTO\ResultadoDoCadastro;
use App\ChatIa\Enum\TipoDaProposta;
use App\Cliente\Entity\Cliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Quem GRAVA o que a proposta pede, pelos UseCases de Cliente e Pasta, com o usuário e o escritório da sessão. Existe como
 * interface para o teste dos guardas do Confirmar não depender dos UseCases de outros domínios.
 */
interface ExecutorDaProposta
{
    /**
     * @param array<string, mixed> $dados     os dados JÁ validados (os que a pessoa confirmou)
     * @param Cliente|null         $existente cadastro do escritório a ligar à pasta no lugar de criar outro
     */
    public function executar(TipoDaProposta $tipo, array $dados, User $eu, Tenant $tenant, ?Cliente $existente): ResultadoDoCadastro;
}
