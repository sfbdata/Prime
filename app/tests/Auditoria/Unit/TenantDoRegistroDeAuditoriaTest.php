<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Service\Audit\AuditLogSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditLogSubscriber::class)]
final class TenantDoRegistroDeAuditoriaTest extends TestCase
{
    #[TestDox('Com sessão disponível vale o escritório da sessão')]
    public function testUsaASessao(): void
    {
        self::assertSame(7, AuditLogSubscriber::tenantDoRegistro(static fn (): ?int => 7, new \stdClass()));
    }

    #[TestDox('Resposta em fluxo: sessão que estoura (headers already sent) cai no escritório da própria entidade')]
    public function testCaiNoEscritorioDaEntidade(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(9);
        $entidade = new class ($tenant) {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function getTenant(): Tenant
            {
                return $this->tenant;
            }
        };
        $estoura = static function (): ?int {
            throw new \RuntimeException('Failed to start the session because headers have already been sent by "EscritorDeSse.php" at line 24.');
        };

        self::assertSame(9, AuditLogSubscriber::tenantDoRegistro($estoura, $entidade));
    }

    #[TestDox('Sem sessão e sem escritório na entidade: nulo, nunca um erro')]
    public function testSemNada(): void
    {
        $estoura = static function (): ?int {
            throw new \RuntimeException('sem sessão');
        };

        self::assertNull(AuditLogSubscriber::tenantDoRegistro($estoura, new \stdClass()));
    }

    private function escritorio(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    /** @return iterable<string, array{object}> */
    public static function entidadesComDono(): iterable
    {
        yield 'TenantAware (pasta)' => [new Pasta()];
        yield 'sede' => [new Sede()];
        yield 'cargo' => [new Cargo()];
        yield 'lotação' => [new Lotacao()];
    }

    #[DataProvider('entidadesComDono')]
    #[TestDox('Auditoria 3.01: entidade com dono grava o escritório do DONO, não o da sessão ($_dataName)')]
    public function testEntidadeComDonoGravaODono(object $entidade): void
    {
        $entidade->setTenant($this->escritorio(9));

        self::assertSame(9, AuditLogSubscriber::tenantDoRegistro(static fn (): ?int => 7, $entidade));
    }

    #[DataProvider('entidadesComDono')]
    #[TestDox('Entidade com dono ainda sem escritório: vale a sessão ($_dataName)')]
    public function testDonoNuloUsaASessao(object $entidade): void
    {
        self::assertSame(7, AuditLogSubscriber::tenantDoRegistro(static fn (): ?int => 7, $entidade));
    }

    #[TestDox('Classe fora da lista, mesmo com getTenant() (vínculo, convite): vale a sessão')]
    public function testForaDaListaUsaASessao(): void
    {
        $vinculo = new UserTenant(new User(), $this->escritorio(9));

        self::assertSame(7, AuditLogSubscriber::tenantDoRegistro(static fn (): ?int => 7, $vinculo));
    }

    #[DataProvider('entidadesComDono')]
    #[TestDox('Sem escritório na sessão (comando, worker): segue sem escritório, mesmo com dono ($_dataName)')]
    public function testSemSessaoSegueSemEscritorio(object $entidade): void
    {
        $entidade->setTenant($this->escritorio(9));

        self::assertNull(AuditLogSubscriber::tenantDoRegistro(static fn (): ?int => null, $entidade));
    }
}
