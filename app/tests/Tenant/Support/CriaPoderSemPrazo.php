<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Support;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\AuditLogRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Repository\ConfiguracaoDeEdicaoRepository;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * Monta o {@see PoderDeEdicaoSemPrazo} e a {@see AuditoriaDaEdicaoSemPrazo} de verdade nos testes unitários (spec
 * `edicao-sem-prazo-master-primario.md`). Sem argumentos ninguém é primário: vale a regra de sempre; o Master é quem
 * tem `ROLE_SUPER_ADMIN` no próprio User. Use num TestCase (precisa de `createStub`).
 */
trait CriaPoderSemPrazo
{
    protected function poderSemPrazo(?UserTenant $vinculoDePrimario = null, bool $opcaoLigada = true): PoderDeEdicaoSemPrazo
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            static fn ($usuario, $tenant): ?UserTenant => $vinculoDePrimario !== null && $vinculoDePrimario->getUser() === $usuario
                && self::mesmoEscritorio($vinculoDePrimario->getTenant(), $tenant) ? $vinculoDePrimario : null,
        );
        $configuracoes = $this->createStub(ConfiguracaoDeEdicaoRepository::class);
        $configuracoes->method('primarioSemPrazo')->willReturn($opcaoLigada);

        return new PoderDeEdicaoSemPrazo(new RegraDosPrimarios($vinculos), $vinculos, $configuracoes);
    }

    /** O primário só vale no escritório do vínculo (o de outro escritório não tem o poder). */
    private static function mesmoEscritorio(Tenant $doVinculo, Tenant $pedido): bool
    {
        return $doVinculo === $pedido || ($pedido->getId() !== null && $doVinculo->getId() === $pedido->getId());
    }

    protected function auditoriaSemPrazo(PoderDeEdicaoSemPrazo $poder, ?AuditLogRepository $trilha = null): AuditoriaDaEdicaoSemPrazo
    {
        return new AuditoriaDaEdicaoSemPrazo($trilha ?? $this->createStub(AuditLogRepository::class), $poder);
    }
}
