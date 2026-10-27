<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Service\EntradaAbertaEmOutroEscritorio;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A batida com o ponto por escritório (spec `docs/specs/ponto-por-escritorio.md` §4, risco ALTO): quem está com o
 * ponto aberto hoje em Y bate a entrada em X e recebe o aviso da pendência em Y (esquecimento nunca trava,
 * `ponto-esquecimento-nao-trava.md` R2); a interjornada e o repouso contam só dentro de cada escritório.
 *
 * X tem home office liberado todo dia para a pessoa (a batida passa sem GPS), salvo onde o teste diz o contrário.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(EntradaAbertaEmOutroEscritorio::class)]
final class PontoPorEscritorioBatidaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private Tenant $x;
    private Tenant $y;
    private User $user;

    protected function setUp(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 5) {
            self::markTestSkipped('Nos primeiros 5 minutos do dia os horários "de hoje" do teste cairiam em ontem.');
        }
        $this->client = static::createClient();
        $this->client->disableReboot();   // várias requisições por teste: o CSRF falso e o banco seguem os mesmos
        $this->instalarCsrfStorage();
        $this->x = $this->tenant('Escritório X');
        $this->y = $this->tenant('Escritório Y');
        $this->user = $this->user(['ROLE_USER']);
        $this->vincular($this->user, $this->x, ['modules.ponto.view']);
        $this->vincular($this->user, $this->y, ['modules.ponto.view']);
        $this->homeOfficeTodoDia($this->user, $this->x);
    }

    #[TestDox('R2: entrada aberta hoje em Y, a entrada em X passa, grava em X e a resposta traz o aviso com o nome de Y, o dia e o link de trocar de escritório')]
    public function testEntradaAbertaEmYPassaComAviso(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));

        $resposta = $this->baterEmX('entrada');

        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['success']);
        self::assertSame([$this->avisoDeY()], $resposta['avisos']);
        self::assertSame(1, $this->batidasEm($this->x));
        self::assertSame(1, $this->batidasEm($this->y), 'nada foi gravado em Y');
    }

    #[TestDox('Com a saída de Y batida, a entrada em X passa e grava o escritório da sessão')]
    public function testSaidaEmYLibera(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->y, 'saida', $this->hojeCedo(2));

        $resposta = $this->baterEmX('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame([], $resposta['avisos'], 'sem ponto aberto em Y, sem aviso');
        self::assertSame(1, $this->batidasEm($this->x));
        self::assertSame(2, $this->batidasEm($this->y), 'nada foi gravado em Y');
    }

    #[TestDox('Com o pedido de esquecimento da saída de hoje em Y ainda pendente, a entrada em X passa e o aviso continua (o ponto em Y segue aberto)')]
    public function testEsquecimentoPendenteMantemOAviso(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $pedido = new JustificativaPonto();
        $pedido->setUser($this->user);
        $pedido->setTenant($this->y);
        $pedido->setData(new \DateTime('today'));
        $pedido->setStatus('pendente');
        $pedido->setTipo(TipoJustificativa::EsquecimentoRegistro->value);
        $pedido->setTipoRegistroEsquecido('saida');
        $pedido->setHoraRegistroEsquecido(new \DateTime('00:00:30'));
        $this->em()->persist($pedido);
        $this->em()->flush();

        $resposta = $this->baterEmX('entrada');

        self::assertTrue($resposta['success']);
        self::assertSame([$this->avisoDeY()], $resposta['avisos']);
        self::assertSame(1, $this->batidasEm($this->x));
    }

    #[TestDox('R2: repouso sem retorno em Y (P-D), a entrada em X passa com o mesmo aviso')]
    public function testRepousoSemRetornoPassaComAviso(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->y, 'repouso', $this->hojeCedo(2));

        $resposta = $this->baterEmX('entrada');

        self::assertTrue($resposta['success']);
        self::assertSame([$this->avisoDeY()], $resposta['avisos']);
        self::assertSame(1, $this->batidasEm($this->x));
    }

    #[TestDox('Entrada sem saída em Y ontem às 23:50: a entrada de hoje em X passa sem aviso de ponto aberto (a janela do aviso é o dia)')]
    public function testOntemPassaSemAviso(): void
    {
        $this->batida($this->y, 'entrada', new \DateTime('yesterday 23:50'));

        $resposta = $this->baterEmX('entrada');

        self::assertTrue($resposta['success']);
        self::assertSame([], $resposta['avisos']);
    }

    #[TestDox('Vínculo inativo em Y não avisa; Y excluído não avisa (separados)')]
    public function testVinculoInativoEEscritorioExcluido(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->y->getId()]);
        self::assertSame([], $this->baterEmX('entrada')['avisos'], 'vínculo inativo');

        $z = $this->tenant('Escritório Z');
        $this->vincular($this->user, $z, ['modules.ponto.view']);
        $this->batida($z, 'entrada', $this->hojeCedo(1));
        $z->setIsActive(false);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('DELETE FROM registro_ponto WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->x->getId()]);
        self::assertSame([], $this->baterEmX('entrada')['avisos'], 'escritório excluído');
    }

    #[TestDox('Y sem o módulo de ponto não avisa (P-C)')]
    public function testSemModuloEmYNaoAvisa(): void
    {
        $z = $this->tenant('Escritório Z');
        $this->vincular($this->user, $z, ['modules.clientes.view']);
        $this->batida($z, 'entrada', $this->hojeCedo(1));

        $resposta = $this->baterEmX('entrada');

        self::assertTrue($resposta['success']);
        self::assertSame([], $resposta['avisos']);
    }

    #[TestDox('Repouso, retorno e saída em X passam com Y aberto, sem o aviso (ele é da entrada)')]
    public function testSoAEntradaAvisa(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->x, 'entrada', $this->hojeCedo(2));
        $this->batida($this->x, 'repouso', $this->hojeCedo(3));
        $this->desligarRepousoMinimoEmX();

        foreach (['retorno', 'repouso', 'saida'] as $tipo) {
            $resposta = $this->baterEmX($tipo);
            self::assertTrue($resposta['success'], $tipo);
            self::assertSame([], $resposta['avisos'], $tipo);
        }
    }

    #[TestDox('Interjornada: a saída recente em Y não conta para a entrada em X; a de X conta')]
    public function testInterjornadaNoEscritorio(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->y, 'saida', $this->agoraHaPouco());
        self::assertTrue($this->baterEmX('entrada')['success'], 'a saída de Y não conta em X');

        $this->batida($this->x, 'entrada', $this->entradaRecente());   // a saída de X fecha uma entrada: conta (R4)
        $this->batida($this->x, 'saida', $this->agoraHaPouco());
        $resposta = $this->baterEmX('entrada');
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Interjornada mínima', $resposta['message']);
    }

    #[TestDox('Y aberto e a interjornada de uma saída batida em X: a recusa é a da interjornada, e o ponto aberto em Y não recusa')]
    public function testYAbertoNaoRecusaMasAInterjornadaSim(): void
    {
        $this->batida($this->x, 'entrada', $this->entradaRecente());   // a saída de X fecha uma entrada: conta (R4)
        $this->batida($this->x, 'saida', $this->agoraHaPouco());
        $this->batida($this->y, 'entrada', $this->entradaRecente());   // Y aberto a qualquer hora do dia

        $resposta = $this->baterEmX('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Interjornada mínima', $resposta['message']);
    }

    #[TestDox('Sem home office em X e sem GPS: Y aberto não recusa; a recusa é a da geolocalização de sempre')]
    public function testYAbertoSemGpsRecusaPelaGeolocalizacao(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM home_office_config WHERE user_id = ?', [$this->user->getId()]);
        $this->batida($this->y, 'entrada', $this->entradaRecente());   // Y aberto a qualquer hora do dia

        self::assertSame('Geolocalização é obrigatória para registrar o ponto.', $this->baterEmX('entrada')['message']);
    }

    #[TestDox('Dentro da área de X (com GPS, sem home office): passa com o mesmo aviso')]
    public function testDentroDaAreaDeX(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM home_office_config WHERE user_id = ?', [$this->user->getId()]);
        $this->sede($this->x);
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));

        $resposta = $this->baterEmX('entrada', ['latitude' => -23.55, 'longitude' => -46.63, 'precisaoGps' => 10.0]);

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame([$this->avisoDeY()], $resposta['avisos']);
    }

    #[TestDox('Super admin: com vínculo em Y aberto, passa com o aviso (N6); sem vínculo em Y, as batidas de Y não avisam')]
    public function testSuperAdmin(): void
    {
        $master = $this->user(['ROLE_SUPER_ADMIN']);
        $this->vincular($master, $this->x, []);
        $this->homeOfficeTodoDia($master, $this->x);
        $this->batida($this->y, 'entrada', $this->hojeCedo(1), $master);   // batida em Y sem vínculo em Y

        $this->logarComTenant($this->client, $master, $this->x);
        self::assertSame([], $this->postar('entrada')['avisos'], 'sem vínculo em Y');

        $this->em()->getConnection()->executeStatement('DELETE FROM registro_ponto WHERE user_id = ? AND tenant_id = ?', [$master->getId(), $this->x->getId()]);
        $this->vincular($master, $this->y, []);
        $resposta = $this->postar('entrada');
        self::assertTrue($resposta['success'], 'com vínculo em Y');
        self::assertSame([$this->avisoDeY()], $resposta['avisos']);
    }

    #[TestDox('Lançamento manual do admin em X (correção, não batida) não é afetado por Y aberto')]
    public function testLancamentoManualDoAdminNaoETravado(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $this->vincular($admin, $this->x, []);
        $this->logarComTenant($this->client, $admin, $this->x);

        $hoje = new \DateTimeImmutable();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/ponto/add', $this->x->getId(), $this->user->getId()), [
            'registro_ponto_manual' => ['data' => $hoje->format('Y-m-d'), 'hora' => '00:01:00', 'tipo' => 'entrada'],
            'competencia' => $hoje->format('Y-m'),
            '_token' => 'TOKEN_ponto_manual_add',
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->batidasEm($this->x));
    }

    #[TestDox('A tela do ponto em X depois da entrada não mostra nada de Y (o ponto aberto de hoje em Y não é pendência da lista)')]
    public function testFolhaDeXSemDadoDeY(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->baterEmX('entrada');

        $this->client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $this->y->getName(), (string) $this->client->getResponse()->getContent());
    }

    // ----------------------------------------------------------------- apoio

    /** @return array{texto: string, link: string, rotulo: string} o aviso de R2 com o ponto aberto hoje em Y */
    private function avisoDeY(): array
    {
        return [
            'texto' => sprintf(EntradaAbertaEmOutroEscritorio::AVISO_PENDENCIA, $this->y->getName(), (new \DateTimeImmutable())->format('d/m')),
            'link' => '/escritorio/selecionar',
            'rotulo' => EntradaAbertaEmOutroEscritorio::ROTULO_TROCAR,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function baterEmX(string $tipo, array $extra = []): array
    {
        $this->logarComTenant($this->client, $this->user, $this->x);

        return $this->postar($tipo, $extra);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function postar(string $tipo, array $extra = []): array
    {
        $this->client->request(
            'POST',
            '/ponto/batida',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode(['tipo' => $tipo] + $extra),
        );

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** Um minuto atrás, ainda hoje (o setUp pula os primeiros 5 minutos do dia): a interjornada ainda corre. */
    private function agoraHaPouco(): \DateTime
    {
        return new \DateTime('-1 minute');
    }

    /**
     * Uma entrada de hoje antes do `agoraHaPouco()` e dentro da janela do turno (16 h) a qualquer hora: 90 minutos atrás,
     * ou logo depois da meia-noite se for cedo.
     */
    private function entradaRecente(): \DateTime
    {
        return \DateTime::createFromInterface(max(\DateTimeImmutable::createFromMutable($this->hojeCedo(3)), new \DateTimeImmutable('-90 minutes')));
    }

    /** Um horário de hoje já passado, no começo do dia (a contar da meia-noite). */
    private function hojeCedo(int $segundos): \DateTime
    {
        return (new \DateTime('today'))->modify('+' . $segundos . ' seconds');
    }

    private function batida(Tenant $tenant, string $tipo, \DateTime $quando, ?User $quem = null): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($quem ?? $this->user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora($quando);
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function batidasEm(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$this->user->getId(), $tenant->getId()],
        );
    }

    /**
     * O escritório do teste nasce sem jornada, e sem jornada o ponto usa o padrão (repouso mínimo de 60 minutos
     * ligado). Antes este método voltava sem fazer nada nesse caso, e o "retorno" logo depois do repouso de
     * hoje cedo era recusado até perto de 01:00 (o teste falhava de 00:05 a 01:00). Agora cria a jornada de X
     * já com a validação do repouso desligada.
     */
    private function desligarRepousoMinimoEmX(): void
    {
        $jornada = $this->x->getJornadaTenant();
        if ($jornada === null) {
            $jornada = (new JornadaTenant())->setTenant($this->x);
            $this->x->setJornadaTenant($jornada);
            $this->em()->persist($jornada);
        }
        $jornada->setValidacaoRepousoHabilitada(false);
        $this->em()->flush();
    }

    /** @param list<string> $codigos */
    private function vincular(User $user, Tenant $tenant, array $codigos): void
    {
        $em = $this->em();
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem(false);
        $em->persist($papel);
        foreach ($codigos as $codigo) {
            $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($permissao === null) {
                $permissao = new Permission();
                $permissao->setCode($codigo);
                $permissao->setDescription($codigo);
                $permissao->setGroup(explode('.', $codigo)[0]);
                $em->persist($permissao);
            }
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($papel);
            $ligacao->setPermission($permissao);
            $em->persist($ligacao);
            $papel->getTenantRolePermissions()->add($ligacao);
        }
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
    }

    private function homeOfficeTodoDia(User $user, Tenant $tenant): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($user);
        $config->setTenant($tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    private function sede(Tenant $tenant): void
    {
        $sede = new Sede();
        $sede->setNome('Sede ' . uniqid());
        $sede->setLatitude('-23.55000000');
        $sede->setLongitude('-46.63000000');
        $sede->setRaioPermitido(100);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $this->em()->persist($sede);
        $this->em()->flush();
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function user(array $papeis): User
    {
        $user = new User();
        $user->setEmail('ponto_escritorio_' . uniqid() . '@test.com');
        $user->setFullName('Ponto por escritório');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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
