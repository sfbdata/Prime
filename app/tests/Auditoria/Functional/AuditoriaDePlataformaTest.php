<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Service\Audit\AuditLogSubscriber;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Zenstruck\Foundry\Test\Factories;

/**
 * AuditLogSubscriber e o escopo de plataforma (spec perfis-1.2-masters M7): com o escopo ligado a
 * linha grava com tenant_id nulo; fora dele, com o escritório da sessão, como sempre.
 */
#[CoversClass(AuditLogSubscriber::class)]
#[CoversClass(EscopoDeAuditoriaDePlataforma::class)]
final class AuditoriaDePlataformaTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private int $tenantId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenantId = (int) TenantFactory::createOne(['name' => 'Escritório da sessão ' . uniqid()])->_real()->getId();

        $sessao = new Session(new MockArraySessionStorage());
        $sessao->set('current_tenant_id', $this->tenantId);
        $requisicao = new Request();
        $requisicao->setSession($sessao);
        static::getContainer()->get(RequestStack::class)->push($requisicao);
    }

    #[TestDox('fora do escopo: a alteração grava com o escritório da sessão')]
    public function testForaDoEscopoGravaComOEscritorio(): void
    {
        $user = $this->alterarNome(dentroDoEscopo: false);

        self::assertSame($this->tenantId, $this->ultimaAlteracao($user)->getTenantId());
    }

    #[TestDox('dentro do escopo de plataforma: a alteração grava sem escritório (tenant_id nulo)')]
    public function testDentroDoEscopoGravaSemEscritorio(): void
    {
        $user = $this->alterarNome(dentroDoEscopo: true);

        self::assertNull($this->ultimaAlteracao($user)->getTenantId());
        self::assertFalse(static::getContainer()->get(EscopoDeAuditoriaDePlataforma::class)->estaLigado(), 'desliga depois');
    }

    private function alterarNome(bool $dentroDoEscopo): User
    {
        $user = UserFactory::createOne(['fullName' => 'Antes'])->_real();
        $user->setFullName('Depois');
        $gravar = fn () => $this->em->flush();

        $dentroDoEscopo
            ? static::getContainer()->get(EscopoDeAuditoriaDePlataforma::class)->executar($gravar)
            : $gravar();

        return $user;
    }

    private function ultimaAlteracao(User $user): AuditLog
    {
        $registro = $this->em->getRepository(AuditLog::class)->findOneBy(
            ['entityClass' => User::class, 'entityId' => (string) $user->getId(), 'action' => 'update'],
            ['id' => 'DESC'],
        );
        self::assertNotNull($registro);

        return $registro;
    }
}
