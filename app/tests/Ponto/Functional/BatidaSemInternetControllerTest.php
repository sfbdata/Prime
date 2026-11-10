<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\FuncoesDoPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Etapa E14 do desenho novo do Ponto (spec ponto-desenho-0811 §5): a batida guardada sem internet no aparelho chega
 * depois, pela fila. Vale a hora do aparelho (até 72 h antes da chegada e até 2 min à frente dela), as duas horas ficam
 * guardadas, todas as regras da batida valem no momento da batida e a folha mostra o dia marcado "Sem internet" para a
 * pessoa e para o gestor.
 *
 * A pessoa das batidas bate em home office liberado todos os dias: a cerca não entra na conta.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class BatidaSemInternetControllerTest extends JusPrimeWebTestCase
{
    private const ID = '0b6f3a52-1d7e-4c89-a2f4-5e6d7c8b9a01';
    private const SELO = 'span.pe-tag.pe-tag--info.pe-tag--extra[title="Batida enviada sem internet: vale a hora do aparelho."]';

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->tenant = $this->criarTenant();
        $this->user = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $this->em()->persist(new UserTenant($this->user, $this->tenant));
        $this->liberarHomeOfficeTodosOsDias();
    }

    #[TestDox('A batida da fila grava a hora do aparelho como hora da batida, a chegada e a marca "enviada sem internet"')]
    public function testFilaGravaAHoraDoAparelho(): void
    {
        $aparelho = new \DateTimeImmutable('-3 hours');
        $antes = new \DateTimeImmutable('-1 second');

        $resposta = $this->postarDaFila('entrada', $aparelho);

        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['success']);
        self::assertSame($aparelho->format('H:i:s'), $resposta['data']['hora'], 'a resposta traz a hora da batida gravada');
        $registro = $this->unicaBatida();
        self::assertSame($aparelho->format('Y-m-d H:i:s'), $registro->getDataHora()?->format('Y-m-d H:i:s'), 'vale a hora do aparelho');
        self::assertSame($aparelho->format('Y-m-d H:i:s'), $registro->getHoraAparelho()?->format('Y-m-d H:i:s'));
        self::assertGreaterThanOrEqual($antes->getTimestamp(), $registro->getHoraChegada()?->getTimestamp(), 'a chegada é a hora oficial do servidor');
        self::assertTrue($registro->isEnviadaSemInternet());
        self::assertSame(self::ID, $registro->getIdEnvio());
    }

    #[TestDox('Batida da fila guardada há 73 h é recusada com 422 e não grava')]
    public function testFilaAlemDoTetoRecusada(): void
    {
        $resposta = $this->postarDaFila('entrada', new \DateTimeImmutable('-73 hours'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([
            'success' => false,
            'message' => 'Esta batida ficou guardada sem internet por mais de 72 horas e não pode mais ser enviada. Peça o esquecimento de registro do dia.',
        ], $resposta);
        self::assertCount(0, $this->batidasDoUsuario());
    }

    #[TestDox('Batida da fila guardada em outro escritório volta 409 e não grava')]
    public function testFilaDeOutroEscritorioVolta409(): void
    {
        $resposta = $this->postarDaFila('entrada', new \DateTimeImmutable('-1 hour'), ['escritorioId' => (int) $this->tenant->getId() + 1]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['success' => false, 'message' => 'Esta batida foi guardada em outra conta ou escritório e não foi enviada.'], $resposta);
        self::assertCount(0, $this->batidasDoUsuario());
    }

    #[TestDox('Batida da fila guardada em outra conta volta 409 e não grava')]
    public function testFilaDeOutraContaVolta409(): void
    {
        $this->postarDaFila('entrada', new \DateTimeImmutable('-1 hour'), ['usuarioId' => (int) $this->user->getId() + 1]);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(0, $this->batidasDoUsuario());
    }

    #[TestDox('Relógio do aparelho mais de 2 min adiantado recusa a batida da fila com 422')]
    public function testFilaComRelogioAdiantadoRecusada(): void
    {
        $this->postarDaFila('entrada', new \DateTimeImmutable('+10 minutes'));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->batidasDoUsuario());
    }

    #[TestDox('Batida da fila sem a conta recusa com 422 e não grava')]
    public function testFilaIncompletaRecusada(): void
    {
        $resposta = $this->postarDaFila('entrada', new \DateTimeImmutable('-1 hour'), ['usuarioId' => null]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A batida guardada sem internet chegou incompleta e não foi registrada.', $resposta['message']);
        self::assertCount(0, $this->batidasDoUsuario());
    }

    #[TestDox('A nova tentativa de uma batida da fila que já entrou responde "já registrada", mesmo depois do teto')]
    public function testFilaJaRegistradaDepoisDoTeto(): void
    {
        $aparelho = new \DateTimeImmutable('-73 hours');
        $this->criarBatida(RegistroPonto::TIPO_ENTRADA, $aparelho, self::ID);

        $resposta = $this->postarDaFila('entrada', $aparelho);

        self::assertResponseIsSuccessful();
        self::assertSame(['hora' => $aparelho->format('H:i:s'), 'tipo' => 'entrada', 'jaRegistrada' => true], $resposta['data']);
        self::assertCount(1, $this->batidasDoUsuario());
    }

    #[TestDox('Com a chave ponto-alertas desligada, o repouso mínimo vale na hora do aparelho: o retorno guardado 20 min depois do repouso é recusado')]
    public function testRepousoMinimoNaHoraDoAparelho(): void
    {
        $this->desligarOsAlertas();
        // Ontem, longe da meia-noite: o retorno chega horas depois, e pela hora da chegada o intervalo estaria cumprido.
        $ontem = new \DateTimeImmutable('yesterday');
        $this->criarBatida(RegistroPonto::TIPO_REPOUSO, $ontem->setTime(12, 0), null);

        $resposta = $this->postarDaFila('retorno', $ontem->setTime(12, 20));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Intervalo mínimo de repouso é de 60 minuto(s). Aguarde mais 40 minuto(s).', $resposta['message']);
        self::assertCount(1, $this->batidasDoUsuario(), 'só o repouso');
    }

    #[TestDox('E11: com os alertas ligados, o retorno da fila com o repouso curto grava na hora do aparelho, sem a janela (a fila não fica presa no alerta)')]
    public function testRetornoDaFilaComRepousoCurtoNaoFicaPreso(): void
    {
        $ontem = new \DateTimeImmutable('yesterday');
        $this->criarBatida(RegistroPonto::TIPO_REPOUSO, $ontem->setTime(12, 0), null);

        $resposta = $this->postarDaFila('retorno', $ontem->setTime(12, 20));

        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertArrayNotHasKey('alerta', $resposta);
        self::assertSame('12:20:00', $resposta['data']['hora']);
        self::assertCount(2, $this->batidasDoUsuario());
    }

    #[TestDox('O retorno guardado depois do repouso mínimo entra com a hora do aparelho')]
    public function testRetornoDepoisDoRepousoEntra(): void
    {
        $ontem = new \DateTimeImmutable('yesterday');
        $this->criarBatida(RegistroPonto::TIPO_REPOUSO, $ontem->setTime(12, 0), null);

        $resposta = $this->postarDaFila('retorno', $ontem->setTime(13, 10));

        self::assertResponseIsSuccessful();
        self::assertSame('13:10:00', $resposta['data']['hora']);
        self::assertCount(2, $this->batidasDoUsuario());
    }

    #[TestDox('A folha da /ponto marca "Sem internet" só no dia com batida da fila')]
    public function testFolhaDaPessoaMarcaOSelo(): void
    {
        [$colaborador, $dia, $outroDia] = $this->colaboradorComDiaDaFila();

        $this->logarComTenant($this->client, $colaborador, $this->tenant);
        $this->em()->clear();
        $tela = $this->client->request('GET', '/ponto/?competencia=' . $dia->format('Y-m'));
        self::assertResponseIsSuccessful();

        $this->assertSeloNoDia($tela, $dia);
        self::assertCount(0, $this->linha($tela, $outroDia)->filter(self::SELO), 'o dia sem batida da fila não leva o selo');
    }

    #[TestDox('A ficha do colaborador vista pelo gestor marca "Sem internet" no dia com batida da fila')]
    public function testFichaDoGestorMarcaOSelo(): void
    {
        [$colaborador, $dia, $outroDia] = $this->colaboradorComDiaDaFila();
        $admin = $this->criarUsuario(['ROLE_USER']);
        $this->vincularAdmin($admin);

        $this->logarComTenant($this->client, $admin, $this->tenant);
        $this->em()->clear();
        $ficha = $this->client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?competencia=%s', $this->tenant->getId(), $colaborador->getId(), $dia->format('Y-m')));
        self::assertResponseIsSuccessful();

        $this->assertSeloNoDia($ficha->filter('#tab-ponto'), $dia);
        self::assertCount(0, $this->linha($ficha, $outroDia)->filter(self::SELO));
    }

    // ----------------------------------------------------------------- apoio

    private function assertSeloNoDia(Crawler $tela, \DateTimeImmutable $dia): void
    {
        $selo = $this->linha($tela, $dia)->filter('td.pe-larga:nth-child(2) > ' . self::SELO);
        self::assertCount(1, $selo, 'o selo fica na célula da semana, junto das outras etiquetas do dia');
        self::assertCount(1, $selo->filter('i.bi.bi-wifi-off[aria-hidden="true"]'));
        self::assertSame('Sem internet', trim($selo->text()));
    }

    private function linha(Crawler $tela, \DateTimeImmutable $dia): Crawler
    {
        $linha = $tela->filter(sprintf('table.pe-tab.pe-resp > tbody > tr.pe-lin[data-chave-dia="%s"]', $dia->format('Y-m-d')));
        self::assertCount(1, $linha, 'a linha do dia ' . $dia->format('Y-m-d') . ' deveria estar na folha');

        return $linha;
    }

    /** @return array{User, \DateTimeImmutable, \DateTimeImmutable} a pessoa, o dia com a batida da fila e um dia sem */
    private function colaboradorComDiaDaFila(): array
    {
        $colaborador = $this->criarUsuario(['ROLE_USER']);
        $this->vincularColaborador($colaborador);
        $dia = new \DateTimeImmutable('first monday of last month');
        $outroDia = $dia->modify('+1 day');
        $this->batidaDe($colaborador, RegistroPonto::TIPO_ENTRADA, $dia->setTime(9, 0), false);
        $this->batidaDe($colaborador, RegistroPonto::TIPO_SAIDA, $dia->setTime(17, 0), true);
        $this->batidaDe($colaborador, RegistroPonto::TIPO_ENTRADA, $outroDia->setTime(9, 0), false);

        return [$colaborador, $dia, $outroDia];
    }

    /**
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private function postarDaFila(string $tipo, \DateTimeImmutable $aparelho, array $troca = []): array
    {
        $this->logarComTenant($this->client, $this->user, $this->tenant);
        $payload = array_merge([
            'tipo' => $tipo,
            'idEnvio' => self::ID,
            'horaAparelho' => $aparelho->getTimestamp() * 1000,
            'semInternet' => true,
            'usuarioId' => $this->user->getId(),
            'escritorioId' => $this->tenant->getId(),
        ], $troca);
        $this->client->request(
            'POST',
            '/ponto/batida',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode($payload),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function unicaBatida(): RegistroPonto
    {
        $batidas = $this->batidasDoUsuario();
        self::assertCount(1, $batidas);

        return $batidas[0];
    }

    /** @return list<RegistroPonto> */
    private function batidasDoUsuario(): array
    {
        $this->em()->clear();

        return array_values($this->em()->getRepository(RegistroPonto::class)->findBy(['user' => $this->user->getId(), 'tenant' => $this->tenant->getId()]));
    }

    private function criarBatida(string $tipo, \DateTimeImmutable $quando, ?string $idEnvio): void
    {
        $registro = $this->novaBatida($this->user, $tipo, $quando);
        $registro->setIdEnvio($idEnvio);
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function batidaDe(User $user, string $tipo, \DateTimeImmutable $quando, bool $semInternet): void
    {
        $registro = $this->novaBatida($user, $tipo, $quando);
        $registro->setEnviadaSemInternet($semInternet);
        $registro->setSedeNomeSnapshot('Teste');
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function novaBatida(User $user, string $tipo, \DateTimeImmutable $quando): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($this->tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(\DateTime::createFromImmutable($quando));

        return $registro;
    }

    private function vincularColaborador(User $user): void
    {
        $papel = new TenantRole();
        $papel->setTenant($this->tenant);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem(false);
        $this->em()->persist($papel);
        $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => 'modules.ponto.view']);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode('modules.ponto.view');
            $permissao->setDescription('modules.ponto.view');
            $permissao->setGroup('modules');
            $this->em()->persist($permissao);
        }
        $ligacao = new TenantRolePermission();
        $ligacao->setTenantRole($papel);
        $ligacao->setPermission($permissao);
        $this->em()->persist($ligacao);
        $papel->getTenantRolePermissions()->add($ligacao);
        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($papel);
        $this->em()->persist($vinculo);
        $this->em()->flush();
    }

    private function vincularAdmin(User $admin): void
    {
        $papel = new TenantRole();
        $papel->setTenant($this->tenant);
        $papel->setName('Administrador ' . uniqid());
        $papel->setIsSystem(true);
        $this->em()->persist($papel);
        $vinculo = new UserTenant($admin, $this->tenant);
        $vinculo->setTenantRole($papel);
        $this->em()->persist($vinculo);
        $this->em()->flush();
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

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Batida sem internet ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function criarUsuario(array $papeis): User
    {
        $user = new User();
        $user->setEmail('sem_internet_' . uniqid() . '@test.com');
        $user->setFullName('Batida sem internet');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function liberarHomeOfficeTodosOsDias(): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($this->user);
        $config->setTenant($this->tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    /** A chave `ponto-alertas` desligada pelo Master (camada Global): vale a recusa de antes da E11. */
    private function desligarOsAlertas(): void
    {
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => FuncoesDoPonto::ALERTAS]) ?? new RecursoEstado(FuncoesDoPonto::ALERTAS);
        $estado->desativar(null, null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
