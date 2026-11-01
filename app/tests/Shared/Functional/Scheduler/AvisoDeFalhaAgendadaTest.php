<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Scheduler;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Notificacoes\LeitorDeFalhasAgendadas;
use App\Shared\Scheduler\Controller\FalhaAgendadaController;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use App\Shared\Scheduler\Twig\AvisoDeFalhaAgendadaExtension;
use App\Shared\Scheduler\UseCase\DispensarFalhasAgendadasUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Aviso de falha das tarefas agendadas (spec scheduler-tarefas-agendadas, "Aviso de falha"): o aviso no topo
 * de toda tela para o Master e o e-mail de AGENDADOR_ALERTA_EMAIL, o "Dispensar" (POST com CSRF, 403 para os
 * demais) e a origem na Central do Master.
 */
#[CoversClass(FalhaAgendadaController::class)]
#[CoversClass(DispensarFalhasAgendadasUseCase::class)]
#[CoversClass(AvisoDeFalhaAgendadaExtension::class)]
#[CoversClass(FalhaAgendadaRepository::class)]
#[CoversClass(LeitorDeFalhasAgendadas::class)]
final class AvisoDeFalhaAgendadaTest extends JusPrimeWebTestCase
{
    private const ENDERECO_DE_ALERTA = 'Alerta.Agendador@Exemplo.com';

    /** Filho direto do contêiner dos avisos do topo, que o _globais põe logo dentro do <body>. */
    private const AVISO = 'body > .ae-avisos > .ae-aviso.ae-aviso--cheio[data-aviso-falha-agendada]';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    /** @var array{env: mixed, server: mixed} */
    private array $ambienteAntes;

    protected function setUp(): void
    {
        $this->ambienteAntes = ['env' => $_ENV['AGENDADOR_ALERTA_EMAIL'] ?? null, 'server' => $_SERVER['AGENDADOR_ALERTA_EMAIL'] ?? null];
        $_ENV['AGENDADOR_ALERTA_EMAIL'] = $_SERVER['AGENDADOR_ALERTA_EMAIL'] = 'ops@exemplo.com, ' . self::ENDERECO_DE_ALERTA;

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarTokensFalsos();
        $this->escritorio = (new Tenant())->setName('Escritório Agendador ' . uniqid());
        $this->em->persist($this->escritorio);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $chave => $global) {
            if ($this->ambienteAntes[$chave] === null) {
                unset($GLOBALS[$global]['AGENDADOR_ALERTA_EMAIL']);
            } else {
                $GLOBALS[$global]['AGENDADOR_ALERTA_EMAIL'] = $this->ambienteAntes[$chave];
            }
        }
        parent::tearDown();
    }

    #[TestDox('Master com falha pendente vê o aviso no topo: nome da tarefa (nunca o comando), hora e data, sem a mensagem do erro')]
    public function testMasterVeOAviso(): void
    {
        $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        $crawler = $this->client->request('GET', '/novidades');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::AVISO));
        $texto = $crawler->filter(self::AVISO)->text();
        self::assertStringContainsString('Tarefa agendada falhou', $texto);
        self::assertStringContainsString('Captação das publicações do DJEN às 03:00 de 23/10.', $texto);
        self::assertStringNotContainsString('app:', $texto, 'nome técnico não aparece ao Master');
        self::assertStringContainsString('Os detalhes ficam registrados pela equipe BlueJus.', $texto);
        self::assertStringNotContainsString('RunCommandFailedException', $texto);
        self::assertCount(1, $crawler->filter(self::AVISO . ' > form[method="post"] > button.af-dispensar'));
    }

    #[TestDox('o aviso lista no máximo 5 falhas, as mais recentes, e diz que há outras')]
    public function testLimiteDoAviso(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->registrarFalha('app:tarefa:' . $i, sprintf('2026-10-%02d 10:%02d:00', $i, $i));
        }
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        $texto = $this->client->request('GET', '/novidades')->filter(self::AVISO)->text();

        self::assertStringContainsString('Tarefa agendada às 10:06 de 06/10.', $texto, 'comando sem nome traduzido vira "Tarefa agendada"');
        self::assertStringNotContainsString('10:01 de 01/10', $texto);
        self::assertStringContainsString('Há outras falhas mais antigas.', $texto);
    }

    #[TestDox('usuário comum não vê o aviso, mesmo com falha pendente')]
    public function testUsuarioComumNaoVe(): void
    {
        $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $this->entrar($this->usuario(['ROLE_USER']));

        $crawler = $this->client->request('GET', '/novidades');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-aviso-falha-agendada]'));
    }

    #[TestDox('com o aviso de espaço junto, os dois ficam empilhados no mesmo contêiner, o de espaço primeiro')]
    public function testDoisAvisosEmpilhados(): void
    {
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn((int) (0.93 * 10 * RetratoDoEspaco::BYTES_POR_GB));
        static::getContainer()->set(ConsumoDeArmazenamentoRepository::class, $consumo);
        $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']), primario: true);

        $crawler = $this->client->request('GET', '/novidades');

        self::assertCount(1, $crawler->filter('body > .ae-avisos'));
        $avisos = $crawler->filter('body > .ae-avisos > .ae-aviso');
        self::assertCount(2, $avisos);
        self::assertNotNull($avisos->eq(0)->attr('data-aviso-espaco'), 'o de espaço vem primeiro');
        self::assertNotNull($avisos->eq(1)->attr('data-aviso-falha-agendada'));
        self::assertStringContainsString('Seu espaço está 93% ocupado', $avisos->eq(0)->text());
    }

    #[TestDox('Master dispensa: todas as pendentes ficam com quem e quando, volta para a página e o aviso some')]
    public function testMasterDispensa(): void
    {
        $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $this->registrarFalha('app:purgar-dados-expirados', '2026-10-23 00:00:00');
        $master = $this->usuario(['ROLE_SUPER_ADMIN']);
        $this->entrar($master);

        $this->dispensar('/novidades');

        self::assertResponseRedirects('/novidades');
        $linhas = $this->conexao()->fetchAllAssociative('SELECT dispensada_em, dispensada_por_id FROM falha_agendada');
        self::assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            self::assertNotNull($linha['dispensada_em']);
            self::assertSame($master->getId(), $linha['dispensada_por_id']);
        }
        self::assertCount(0, $this->client->request('GET', '/novidades')->filter('[data-aviso-falha-agendada]'));
    }

    #[TestDox('o e-mail de AGENDADOR_ALERTA_EMAIL (sem diferença de maiúsculas) vê e dispensa; o aviso some para o Master também')]
    public function testEnderecoDeAlertaDispensa(): void
    {
        $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $alerta = $this->usuario(['ROLE_USER'], strtolower(self::ENDERECO_DE_ALERTA));
        $this->entrar($alerta);
        self::assertCount(1, $this->client->request('GET', '/novidades')->filter(self::AVISO));

        $this->dispensar('/novidades');

        self::assertResponseRedirects('/novidades');
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));
        self::assertCount(0, $this->client->request('GET', '/novidades')->filter('[data-aviso-falha-agendada]'));
    }

    #[TestDox('usuário comum que posta o Dispensar recebe 403 e nada é dispensado')]
    public function testUsuarioComumRecebe403(): void
    {
        $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $this->entrar($this->usuario(['ROLE_USER']));

        $this->dispensar('/novidades');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM falha_agendada WHERE dispensada_em IS NULL'));
    }

    #[TestDox('CSRF inválido é recusado e nada é dispensado')]
    public function testCsrfInvalido(): void
    {
        $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        $this->client->request('POST', '/agendador/falhas/dispensar', ['_token' => 'errado', 'voltar' => '/novidades']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM falha_agendada WHERE dispensada_em IS NULL'));
    }

    #[TestDox('voltar para fora do sistema cai no início (sem redirecionamento aberto)')]
    public function testVoltarParaForaCaiNoInicio(): void
    {
        $this->entrar($this->usuario(['ROLE_SUPER_ADMIN']));

        foreach (['https://mal.example/x', '//mal.example/x', '/\\mal.example', '/%2F%2Fmal.example', ''] as $voltar) {
            $this->dispensar($voltar);
            self::assertResponseRedirects('/inicio', null, $voltar);
        }
    }

    #[TestDox('a Central do Master lista a falha, do Sistema BlueJus, sem resposta, mesmo depois de dispensada')]
    public function testCentralDoMaster(): void
    {
        $id = $this->registrarFalha('app:djen:sincronizar', '2026-10-23 03:00:00');
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->entrar($master);
        $this->dispensar('/novidades');

        $this->client->request('GET', '/master/notificacoes?' . http_build_query(['aba' => 'rec', 'q' => 'DJEN']));
        self::assertResponseIsSuccessful();
        $itens = array_column((array) json_decode((string) $this->client->getResponse()->getContent(), true)['itens'], null, 'chave');
        $item = $itens[LeitorDeFalhasAgendadas::ORIGEM . ':' . $id] ?? null;

        self::assertNotNull($item);
        self::assertSame(['alerta', 'Sistema BlueJus', 'Tarefa agendada falhou', false], [$item['tipo'], $item['de'], $item['assunto'], $item['podeResponder']]);
        self::assertSame('Tarefa agendada falhou: Captação das publicações do DJEN às 03:00 de 23/10. Os detalhes ficam registrados pela equipe BlueJus.', $item['texto']);
    }

    private function registrarFalha(string $comando, string $em): int
    {
        static::getContainer()->get(FalhaAgendadaRepository::class)
            ->registrar($comando, new \DateTimeImmutable($em), 'Symfony\Component\Console\Exception\RunCommandFailedException', 1);

        return (int) $this->conexao()->lastInsertId();
    }

    private function dispensar(string $voltar): void
    {
        $this->client->request('POST', '/agendador/falhas/dispensar', ['_token' => 'TOKEN_' . FalhaAgendadaController::CSRF, 'voltar' => $voltar]);
    }

    private function entrar(User $usuario, bool $primario = false): void
    {
        // Uma requisição anterior limpa o EntityManager: o escritório volta a ser gerenciado aqui.
        $this->escritorio = $this->em->find(Tenant::class, $this->escritorio->getId()) ?? $this->escritorio;
        $vinculo = new UserTenant($usuario, $this->escritorio);
        if ($primario) {
            // Papel de sistema = usuário primário, o único que vê o aviso de espaço.
            $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Primário ' . uniqid())->setIsSystem(true);
            $this->em->persist($papel);
            $vinculo->setTenantRole($papel);
        }
        $this->em->persist($vinculo);
        $this->em->flush();
        $this->logarComTenant($this->client, $usuario, $this->escritorio);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, ?string $email = null): User
    {
        $user = (new User())->setEmail($email ?? 'agendador_' . uniqid() . '@test.com')->setFullName('Pessoa Agendador')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function instalarTokensFalsos(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }
}
