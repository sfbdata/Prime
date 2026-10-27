<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;

/**
 * A chave "Registra ponto eletrônico" na batida (spec seguranca-b-jornada, regra 2): desligada no escritório, a pessoa
 * não bate ponto ali, mesmo com a permissão do módulo. Sem vínculo (o Master que entra sem vínculo) não há chave a ler,
 * e a batida segue como antes.
 */
final class ChaveDoPontoEletronico
{
    public const MENSAGEM_DESLIGADA = 'O registro de ponto eletrônico está desligado para você neste escritório. Fale com o administrador.';

    public function __construct(
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    public function estaDesligada(User $user, Tenant $tenant): bool
    {
        return $this->vinculos->findAtivoPorUserETenant($user, $tenant)?->isRegistraPonto() === false;
    }
}
