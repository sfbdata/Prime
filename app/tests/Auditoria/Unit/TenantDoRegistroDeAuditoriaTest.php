<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Unit;

use App\Entity\Tenant\Tenant;
use App\Service\Audit\AuditLogSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
