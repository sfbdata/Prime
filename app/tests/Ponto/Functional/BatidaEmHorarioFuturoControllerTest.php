<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Batida em horário futuro: nas duas portas que gravam batida esquecida (lote do admin e pedido do
 * colaborador) e na aprovação do pedido, a data e a hora acima de agora, no relógio do servidor (a
 * régua da gravação), são recusadas sem gravar nada. Agora e antes passam.
 *
 * O relógio fica parado em HOJE ao meio-dia no fuso do servidor: o teste não depende da hora em que roda.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(PontoController::class)]
#[CoversClass(RegistrarBatidaDoEsquecimentoUseCase::class)]
#[CoversClass(HorarioFuturoDaBatida::class)]
final class BatidaEmHorarioFuturoControllerTest extends JusPrimeWebTestCase
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
    }

    #[TestDox('Lote do admin com hora futura de hoje é recusado e não grava justificativa nem batida')]
    public function testLoteDoAdminComHoraFuturaNaoGravaNada(): void
    {
        $this->logarAdmin();

        $this->enviarLoteDoAdmin('12:01');

        self::assertResponseRedirects();
        self::assertSame([HorarioFuturoDaBatida::MENSAGEM], $this->avisos());
        self::assertSame([], $this->justificativas());
        self::assertSame([], $this->batidas());
    }

    #[TestDox('Lote do admin com hora passada de hoje grava a batida')]
    public function testLoteDoAdminComHoraPassadaGrava(): void
    {
        $this->logarAdmin();

        $this->enviarLoteDoAdmin('11:59');

        self::assertResponseRedirects();
        self::assertSame([], $this->avisos());
        self::assertSame([$this->hoje . ' 11:59'], $this->batidas());
    }

    #[TestDox('Lote do admin com a hora de agora passa')]
    public function testLoteDoAdminComAHoraDeAgoraPassa(): void
    {
        $this->logarAdmin();

        $this->enviarLoteDoAdmin('12:00');

        self::assertSame([], $this->avisos());
        self::assertSame([$this->hoje . ' 12:00'], $this->batidas());
    }

    #[TestDox('Pedido de esquecimento do colaborador com hora futura de hoje é recusado e não grava nada')]
    public function testPedidoDoColaboradorComHoraFuturaNaoGravaNada(): void
    {
        $this->logarComTenant($this->client, $this->colaborador, $this->tenant);

        $this->enviarPedidoDoColaborador('13:00');

        self::assertResponseRedirects('/ponto/');
        self::assertSame([HorarioFuturoDaBatida::MENSAGEM], $this->avisos());
        self::assertSame([], $this->justificativas());
    }

    #[TestDox('Pedido de esquecimento do colaborador com hora passada de hoje fica pendente')]
    public function testPedidoDoColaboradorComHoraPassadaFicaPendente(): void
    {
        $this->logarComTenant($this->client, $this->colaborador, $this->tenant);

        $this->enviarPedidoDoColaborador('11:00');

        self::assertSame([], $this->avisos());
        self::assertSame([[$this->hoje, 'pendente']], $this->justificativas());
    }

    #[TestDox('Aprovar um pedido antigo com hora futura é recusado: segue pendente e sem batida')]
    public function testAprovacaoDePedidoComHoraFuturaERecusada(): void
    {
        $id = (int) $this->criarPedidoPendente('lote-' . uniqid(), '15:00')->getId();
        $this->logarAdmin();

        $this->client->request('POST', sprintf(
            '/tenant/%d/user/%d/justificativa/%d/aprovar',
            $this->tenant->getId(),
            $this->colaborador->getId(),
            $id,
        ), ['_token' => 'TOKEN_justificativa_aprovar_' . $id]);

        self::assertResponseRedirects();
        self::assertSame([HorarioFuturoDaBatida::MENSAGEM], $this->avisos());
        self::assertSame([[$this->hoje, 'pendente']], $this->justificativas());
        self::assertSame([], $this->batidas());
    }

    #[TestDox('Aprovar todos abona o pedido passado e deixa pendente o de hora futura')]
    public function testAprovarTodosDeixaPendenteOPedidoFuturo(): void
    {
        $lote = 'lote-' . uniqid();
        $passado = (int) $this->criarPedidoPendente($lote, '08:00')->getId();
        $futuro  = (int) $this->criarPedidoPendente($lote, '18:00')->getId();
        $this->logarAdmin();

        $this->client->request('POST', sprintf(
            '/tenant/%d/user/%d/justificativa/batch/%s/aprovar-todos',
            $this->tenant->getId(),
            $this->colaborador->getId(),
            $lote,
        ), ['_token' => 'TOKEN_justificativa_aprovar_todos_' . $lote]);

        self::assertResponseRedirects();
        self::assertSame([HorarioFuturoDaBatida::MENSAGEM], $this->avisos());
        $em = $this->emLimpo();
        self::assertSame('abonado', $em->find(JustificativaPonto::class, $passado)?->getStatus());
        self::assertSame('pendente', $em->find(JustificativaPonto::class, $futuro)?->getStatus());
        self::assertSame([$this->hoje . ' 08:00'], $this->batidas());
    }

    #[TestDox('Aprovar todos com o lote inteiro no futuro mostra só o aviso de horário futuro')]
    public function testAprovarTodosComLoteTodoNoFuturo(): void
    {
        $lote = 'lote-' . uniqid();
        $this->criarPedidoPendente($lote, '13:00');
        $this->criarPedidoPendente($lote, '18:00');
        $this->logarAdmin();

        $this->client->request('POST', sprintf(
            '/tenant/%d/user/%d/justificativa/batch/%s/aprovar-todos',
            $this->tenant->getId(),
            $this->colaborador->getId(),
            $lote,
        ), ['_token' => 'TOKEN_justificativa_aprovar_todos_' . $lote]);

        self::assertResponseRedirects();
        self::assertSame([HorarioFuturoDaBatida::MENSAGEM], $this->avisos());
        self::assertSame([], $this->client->getRequest()->getSession()->getFlashBag()->peek('success'));
        self::assertSame([[$this->hoje, 'pendente'], [$this->hoje, 'pendente']], $this->justificativas());
        self::assertSame([], $this->batidas());
    }

    // ----------------------------------------------------------------- helpers

    private function logarAdmin(): void
    {
        $admin = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $this->vincular($admin, $this->tenant);
        $this->logarComTenant($this->client, $admin, $this->tenant);
    }

    private function enviarLoteDoAdmin(string $hora): void
    {
        $this->client->request(
            'POST',
            sprintf('/tenant/%d/user/%d/justificativa/nova', $this->tenant->getId(), $this->colaborador->getId()),
            [
                '_token'                => 'TOKEN_admin_nova_justificativa_' . $this->colaborador->getId(),
                'tipo'                  => 'esquecimento_registro',
                'datas'                 => $this->hoje,
                'tipoRegistroEsquecido' => 'entrada',
                'horaRegistroEsquecido' => $hora,
            ],
        );
    }

    private function enviarPedidoDoColaborador(string $hora): void
    {
        $this->client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => [
                '_token'                => 'TOKEN_justificativa_ponto',
                'tipo'                  => 'esquecimento_registro',
                'datas'                 => $this->hoje,
                'tipoRegistroEsquecido' => 'saida',
                'horaRegistroEsquecido' => $hora,
            ],
        ]);
    }

    /** @return list<string> */
    private function avisos(): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek('warning');
    }

    /** @return list<array{string, string|null}> */
    private function justificativas(): array
    {
        $lista = $this->emLimpo()->getRepository(JustificativaPonto::class)
            ->findBy(['user' => $this->colaborador->getId()], ['id' => 'ASC']);

        return array_map(static fn (JustificativaPonto $j): array => [$j->getData()->format('Y-m-d'), $j->getStatus()], $lista);
    }

    /** @return list<string> */
    private function batidas(): array
    {
        $lista = $this->emLimpo()->getRepository(RegistroPonto::class)
            ->findBy(['user' => $this->colaborador->getId()], ['dataHora' => 'ASC']);

        return array_map(static fn (RegistroPonto $r): string => (string) $r->getDataHora()?->format('Y-m-d H:i'), $lista);
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

    private function criarPedidoPendente(string $lote, string $hora): JustificativaPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($this->colaborador);
        $justificativa->setTenant($this->tenant);
        $justificativa->setData(new \DateTime($this->hoje));
        $justificativa->setStatus('pendente');
        $justificativa->setBatchId($lote);
        $justificativa->setTipo('esquecimento_registro');
        $justificativa->setTipoRegistroEsquecido('entrada');
        $justificativa->setHoraRegistroEsquecido(new \DateTime('1970-01-01 ' . $hora . ':00'));
        $em->persist($justificativa);
        $em->flush();

        return $justificativa;
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HORA FUTURA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * @param list<string> $papeis
     */
    private function criarUsuario(array $papeis = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('hora_futura_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles($papeis);
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
