<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\UserTenantRepository;
use Psr\Clock\ClockInterface;

/**
 * Quem recebe os avisos e as pendências de ponto num escritório (decisão do dono, 23/10; spec
 * `ponto-esquecimento-nao-trava.md` R7): quem tem a chave "Registra ponto eletrônico" ligada no vínculo ativo deste
 * escritório OU bateu ponto aqui nos últimos 30 dias. Um lugar só para a lista de pendências da tela do ponto, o aviso
 * e o atalho do cabeçalho, o alerta de horário, os avisos da batida e o cartão de pendência diário.
 */
final class PublicoDosAvisosDePonto
{
    public const JANELA_DIAS = 30;

    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly RegistroPontoRepository $registros,
        private readonly ClockInterface $clock,
    ) {
    }

    public function recebe(User $user, Tenant $tenant): bool
    {
        if ($this->vinculos->findAtivoPorUserETenant($user, $tenant)?->isRegistraPonto() === true) {
            return true;
        }
        $agora = $this->clock->now();

        return $this->registros->ultimaBatidaNoTenant($user, $tenant, $agora->modify(sprintf('-%d days', self::JANELA_DIAS)), $agora) !== null;
    }
}
