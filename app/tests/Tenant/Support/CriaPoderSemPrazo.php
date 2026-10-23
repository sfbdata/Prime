<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Support;

use App\Entity\Auth\User;
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
    /** @param list<UserTenant> $outrosVinculos vínculos ativos de quem não é primário (ex.: o autor da mensagem do TE) */
    protected function poderSemPrazo(?UserTenant $vinculoDePrimario = null, bool $opcaoLigada = true, array $outrosVinculos = []): PoderDeEdicaoSemPrazo
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            static function ($usuario, $tenant) use ($vinculoDePrimario, $outrosVinculos): ?UserTenant {
                foreach (array_merge($vinculoDePrimario !== null ? [$vinculoDePrimario] : [], $outrosVinculos) as $vinculo) {
                    if (self::mesmaPessoa($vinculo->getUser(), $usuario) && self::mesmoEscritorio($vinculo->getTenant(), $tenant)) {
                        return $vinculo;
                    }
                }

                return null;
            },
        );
        $configuracoes = $this->createStub(ConfiguracaoDeEdicaoRepository::class);
        $configuracoes->method('primarioSemPrazo')->willReturn($opcaoLigada);

        return new PoderDeEdicaoSemPrazo(new RegraDosPrimarios($vinculos), $vinculos, $configuracoes);
    }

    private static function mesmaPessoa(User $doVinculo, User $pedido): bool
    {
        return $doVinculo === $pedido || ($pedido->getId() !== null && $doVinculo->getId() === $pedido->getId());
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
