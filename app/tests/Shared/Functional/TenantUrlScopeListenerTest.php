<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Shared\EventListener\TenantUrlScopeListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A trava automática (B5) fixa o TenantFilter no {tenantId} da URL. Prova comportamental:
 * com a sonda Notificacao (TenantAware desde o M4), após o listener pinar no tenant A, só os
 * dados de A são visíveis — independentemente de não haver tenant na sessão (cenário do super-admin).
 */
#[CoversClass(TenantUrlScopeListener::class)]
final class TenantUrlScopeListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        if ($this->em->getFilters()->isEnabled('tenant')) {
            $this->em->getFilters()->disable('tenant');
        }
    }

    #[TestDox('Com {tenantId} na rota, fixa o filtro naquele tenant (só dados do tenant da URL)')]
    public function testFixaFiltroNoTenantDaUrl(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $this->criarNotificacao($user, $tenantA, 'De A');
        $this->criarNotificacao($user, $tenantB, 'De B');

        ($this->listener())($this->evento(['tenantId' => (string) $tenantA->getId()]));
        $this->em->clear();

        $notifs = $this->em->getRepository(Notificacao::class)->findAll();
        self::assertCount(1, $notifs, 'a trava deveria escopar no tenant da URL');
        self::assertSame('De A', $notifs[0]->getTitulo());
    }

    #[TestDox('Sobrescreve o pin da sessão: filtro já em B, URL=A → só dados de A')]
    public function testSobrescreveOPinDaSessao(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $this->criarNotificacao($user, $tenantA, 'De A');
        $this->criarNotificacao($user, $tenantB, 'De B');

        // Simula o TenantFilterListener: o filtro já está pinado no tenant da SESSÃO = B.
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenantB->getId(), Types::INTEGER);

        // A trava (URL = A) deve SOBRESCREVER o pin da sessão (comportamento central do B5).
        ($this->listener())($this->evento(['tenantId' => (string) $tenantA->getId()]));
        $this->em->clear();

        $notifs = $this->em->getRepository(Notificacao::class)->findAll();
        self::assertCount(1, $notifs, 'a trava deveria sobrescrever o pin da sessão');
        self::assertSame('De A', $notifs[0]->getTitulo(), 'URL=A deve vencer a sessão=B');
    }

    #[TestDox('Sem {tenantId} na rota, não pina nada (filtro permanece desligado)')]
    public function testSemTenantIdNaoPina(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $this->criarNotificacao($user, $tenantA, 'De A');
        $this->criarNotificacao($user, $tenantB, 'De B');

        ($this->listener())($this->evento([]));
        $this->em->clear();

        self::assertCount(2, $this->em->getRepository(Notificacao::class)->findAll(), 'sem {tenantId} o filtro não pode pinar');
    }

    #[TestDox('Em sub-request, não faz nada')]
    public function testSubRequestNaoPina(): void
    {
        $tenantA = $this->criarTenant();
        $user = $this->criarUser();
        $this->criarNotificacao($user, $tenantA, 'De A');

        $evento = new RequestEvent(
            self::$kernel,
            $this->requestComAtributos(['tenantId' => (string) $tenantA->getId()]),
            HttpKernelInterface::SUB_REQUEST,
        );
        ($this->listener())($evento);

        self::assertFalse($this->em->getFilters()->isEnabled('tenant'), 'sub-request não pode pinar');
    }

    // ----------------------------------------------------------------- helpers

    private function listener(): TenantUrlScopeListener
    {
        return new TenantUrlScopeListener($this->em);
    }

    private function evento(array $attrs): RequestEvent
    {
        return new RequestEvent(self::$kernel, $this->requestComAtributos($attrs), HttpKernelInterface::MAIN_REQUEST);
    }

    private function requestComAtributos(array $attrs): Request
    {
        $request = new Request();
        foreach ($attrs as $k => $v) {
            $request->attributes->set($k, $v);
        }

        return $request;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Trava ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('trava_' . uniqid() . '@test.com');
        $user->setFullName('User Trava');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarNotificacao(User $user, Tenant $tenant, string $titulo): void
    {
        $notif = new Notificacao();
        $notif->setUsuario($user);
        $notif->setTenant($tenant);
        $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $notif->setTitulo($titulo);
        $this->em->persist($notif);
        $this->em->flush();
    }
}
