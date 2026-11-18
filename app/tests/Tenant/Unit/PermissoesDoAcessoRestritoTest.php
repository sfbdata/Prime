<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Tenant\TenantRole;
use App\Tenant\Service\PermissoesDoAcessoRestrito;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Spec acesso-restrito-e-compartilhar §5.1 item 1: lista de liberação, e não de bloqueio. */
#[CoversClass(PermissoesDoAcessoRestrito::class)]
final class PermissoesDoAcessoRestritoTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function liberadas(): iterable
    {
        foreach (PermissoesDoAcessoRestrito::LIBERADAS as $codigo) {
            yield $codigo => [$codigo];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function fechadas(): iterable
    {
        foreach ([
            'modules.dashboard.view', 'modules.agenda.view', 'modules.cobranca.view', 'modules.chat.view',
            'modules.inteligencia.view', 'modules.voto.view', 'modules.assinatura.view', 'resources.agenda.ver_prazos',
            'admin.users.manage', 'admin.roles.manage', 'modules.permissao_que_ainda_nao_existe.view',
        ] as $codigo) {
            yield $codigo => [$codigo];
        }
    }

    #[DataProvider('liberadas')]
    #[TestDox('restrito mantém $codigo')]
    public function testRestritoMantemAsLiberadas(string $codigo): void
    {
        self::assertFalse(PermissoesDoAcessoRestrito::bloqueia($this->restrito(), $codigo));
    }

    #[DataProvider('fechadas')]
    #[TestDox('restrito perde $codigo, inclusive permissão nova do catálogo')]
    public function testRestritoPerdeOResto(string $codigo): void
    {
        self::assertTrue(PermissoesDoAcessoRestrito::bloqueia($this->restrito(), $codigo));
    }

    #[DataProvider('fechadas')]
    #[TestDox('perfil comum não perde nada ($codigo)')]
    public function testPerfilComumNaoMuda(string $codigo): void
    {
        self::assertFalse(PermissoesDoAcessoRestrito::bloqueia(new TenantRole(), $codigo));
        self::assertFalse(PermissoesDoAcessoRestrito::bloqueia(null, $codigo));
    }

    private function restrito(): TenantRole
    {
        return (new TenantRole())->restringirAsPastasSobResponsabilidade('Prospectador');
    }
}
