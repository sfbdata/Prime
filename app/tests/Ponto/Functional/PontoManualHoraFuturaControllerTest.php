<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Lançamento e edição manual de batida pelo admin: data e hora acima de agora, no relógio do
 * servidor (a mesma régua da batida esquecida), são recusadas sem gravar nada. Agora e antes passam.
 *
 * O relógio fica parado em HOJE ao meio-dia no fuso do servidor: o teste não depende da hora em que roda.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(HorarioFuturoDaBatida::class)]
final class PontoManualHoraFuturaControllerTest extends JusPrimeWebTestCase
{
    use ClockSensitiveTrait;
    use LiberaSessaoDoRelogioFixoTrait;

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $colaborador;
    private string $hoje;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->hoje = (new \DateTimeImmutable('today'))->format('Y-m-d');
        self::mockTime(new \DateTimeImmutable($this->hoje . ' 12:00:00', new \DateTimeZone(date_default_timezone_get())));
        $this->liberarSessaoDoRelogioFixo();

        $this->tenant      = $this->criarTenant();
        $this->colaborador = $this->criarUsuario();
        $this->vincular($this->colaborador, $this->tenant);
        $this->instalarCsrfStorage();

        $admin = $this->criarUsuario();
        $this->vincular($admin, $this->tenant);
        $this->logarComTenant($this->client, $admin, $this->tenant);
    }

    #[TestDox('Lançamento manual com hora futura de hoje é recusado e não grava a batida')]
    public function testLancamentoComHoraFuturaNaoGrava(): void
    {
        $this->lancar($this->hoje, '12:00:01');

        self::assertResponseRedirects();
        self::assertSame([HorarioFuturoDaBatida::MENSAGEM], $this->flashes('danger'));
        self::assertSame([], $this->batidas());
    }

    #[TestDox('Lançamento manual com data de amanhã é recusado e não grava a batida')]
    public function testLancamentoComDataDeAmanhaNaoGrava(): void
    {
        $this->lancar((new \DateTimeImmutable($this->hoje . ' +1 day'))->format('Y-m-d'), '08:00:00');

        self::assertSame([HorarioFuturoDaBatida::MENSAGEM], $this->flashes('danger'));
        self::assertSame([], $this->batidas());
    }

    #[TestDox('Lançamento manual com hora passada de hoje grava a batida')]
    public function testLancamentoComHoraPassadaGrava(): void
    {
        $this->lancar($this->hoje, '11:59:59');

        self::assertResponseRedirects();
        self::assertSame([], $this->flashes('danger'));
        self::assertSame([$this->hoje . ' 11:59:59'], $this->batidas());
    }

    #[TestDox('Lançamento manual com a hora exata de agora passa')]
    public function testLancamentoComAHoraDeAgoraPassa(): void
    {
        $this->lancar($this->hoje, '12:00:00');

        self::assertSame([], $this->flashes('danger'));
        self::assertSame([$this->hoje . ' 12:00:00'], $this->batidas());
    }

    #[TestDox('Lançamento manual com hora vazia mostra o erro de campos, não o de horário futuro, e não grava')]
    public function testLancamentoComFormularioInvalidoMostraErroDeCampos(): void
    {
        $this->lancar($this->hoje, '');

        self::assertResponseRedirects();
        self::assertSame(['Erro ao registrar batida. Verifique os campos.'], $this->flashes('danger'));
        self::assertSame([], $this->batidas());
    }

    #[TestDox('Edição manual para hora futura é recusada e a batida segue como estava')]
    public function testEdicaoParaHoraFuturaNaoAltera(): void
    {
        $id = $this->criarBatida($this->hoje . ' 08:00:00');

        $this->editar($id, $this->hoje, '13:00:00');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', HorarioFuturoDaBatida::MENSAGEM);
        self::assertSame([$this->hoje . ' 08:00:00'], $this->batidas());
        self::assertSame(RegistroPonto::TIPO_ENTRADA, $this->emLimpo()->find(RegistroPonto::class, $id)?->getTipo());
    }

    #[TestDox('Edição manual para hora passada de hoje grava a nova hora')]
    public function testEdicaoParaHoraPassadaGrava(): void
    {
        $id = $this->criarBatida($this->hoje . ' 08:00:00');

        $this->editar($id, $this->hoje, '11:30:00');

        self::assertResponseRedirects();
        self::assertSame([$this->hoje . ' 11:30:00'], $this->batidas());
    }

    #[TestDox('Edição manual para a hora exata de agora passa')]
    public function testEdicaoParaAHoraDeAgoraPassa(): void
    {
        $id = $this->criarBatida($this->hoje . ' 08:00:00');

        $this->editar($id, $this->hoje, '12:00:00');

        self::assertResponseRedirects();
        self::assertSame([$this->hoje . ' 12:00:00'], $this->batidas());
    }

    // ----------------------------------------------------------------- helpers

    private function lancar(string $data, string $hora): void
    {
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/ponto/add', $this->tenant->getId(), $this->colaborador->getId()), [
            'registro_ponto_manual' => ['data' => $data, 'hora' => $hora, 'tipo' => 'entrada'],
            'competencia'           => substr($this->hoje, 0, 7),
            '_token'                => 'TOKEN_ponto_manual_add',
        ]);
    }

    private function editar(int $id, string $data, string $hora): void
    {
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/ponto/%d/edit', $this->tenant->getId(), $this->colaborador->getId(), $id), [
            'registro_ponto_manual' => ['data' => $data, 'hora' => $hora, 'tipo' => 'saida'],
            '_token'                => 'TOKEN_ponto_manual_edit',
        ]);
    }

    /** @return list<string> */
    private function flashes(string $tipo): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($tipo);
    }

    /** @return list<string> */
    private function batidas(): array
    {
        $lista = $this->emLimpo()->getRepository(RegistroPonto::class)
            ->findBy(['user' => $this->colaborador->getId()], ['dataHora' => 'ASC']);

        return array_map(static fn (RegistroPonto $r): string => (string) $r->getDataHora()?->format('Y-m-d H:i:s'), $lista);
    }

    private function emLimpo(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em;
    }

    private function criarBatida(string $dataHora): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($this->colaborador);
        $registro->setTenant($this->tenant);
        $registro->setDataHora(new \DateTime($dataHora));
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $em->persist($registro);
        $em->flush();

        return (int) $registro->getId();
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant MANUAL FUTURO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('manual_futuro_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Vínculo ativo com role isSystem (bypassa o PermissionChecker, como nos vizinhos). */
    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
