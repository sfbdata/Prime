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
 * ponto aberto hoje em Y não bate a entrada em X; a interjornada e o repouso contam só dentro de cada escritório.
 *
 * X tem home office liberado todo dia para a pessoa (a batida passa sem GPS), salvo onde o teste diz o contrário: é
 * também o caso "home office em X não muda nada, a trava vem antes".
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

    #[TestDox('Entrada aberta hoje em Y: a entrada em X volta 422 com a frase do dono e o nome de Y, só success e message, e nada é gravado')]
    public function testEntradaAbertaEmYTrava(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));

        $resposta = $this->baterEmX('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['success' => false, 'message' => sprintf(EntradaAbertaEmOutroEscritorio::MENSAGEM_TRAVA, $this->y->getName())], $resposta);
        self::assertSame(0, $this->batidasEm($this->x));
    }

    #[TestDox('Com a saída de Y batida, a entrada em X passa e grava o escritório da sessão')]
    public function testSaidaEmYLibera(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->y, 'saida', $this->hojeCedo(2));

        $resposta = $this->baterEmX('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame(1, $this->batidasEm($this->x));
        self::assertSame(2, $this->batidasEm($this->y), 'nada foi gravado em Y');
    }

    #[TestDox('P-A (dono, 19/10): o pedido de esquecimento da saída de hoje em Y, ainda pendente, não solta a trava')]
    public function testEsquecimentoPendenteNaoSolta(): void
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

        $this->baterEmX('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->batidasEm($this->x));
    }

    #[TestDox('Repouso sem retorno em Y ainda trava (P-D)')]
    public function testRepousoSemRetornoTrava(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->y, 'repouso', $this->hojeCedo(2));

        $this->baterEmX('entrada');

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Entrada sem saída em Y ontem às 23:50 não trava a entrada de hoje em X (variante do mesmo dia)')]
    public function testOntemNaoTrava(): void
    {
        $this->batida($this->y, 'entrada', new \DateTime('yesterday 23:50'));

        self::assertTrue($this->baterEmX('entrada')['success']);
    }

    #[TestDox('Vínculo inativo em Y não trava; Y excluído não trava (separados)')]
    public function testVinculoInativoEEscritorioExcluido(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->y->getId()]);
        self::assertTrue($this->baterEmX('entrada')['success'], 'vínculo inativo');

        $z = $this->tenant('Escritório Z');
        $this->vincular($this->user, $z, ['modules.ponto.view']);
        $this->batida($z, 'entrada', $this->hojeCedo(1));
        $z->setIsActive(false);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('DELETE FROM registro_ponto WHERE user_id = ? AND tenant_id = ?', [$this->user->getId(), $this->x->getId()]);
        self::assertTrue($this->baterEmX('entrada')['success'], 'escritório excluído');
    }

    #[TestDox('Y sem o módulo de ponto não trava (P-C)')]
    public function testSemModuloEmYNaoTrava(): void
    {
        $z = $this->tenant('Escritório Z');
        $this->vincular($this->user, $z, ['modules.clientes.view']);
        $this->batida($z, 'entrada', $this->hojeCedo(1));

        self::assertTrue($this->baterEmX('entrada')['success']);
    }

    #[TestDox('Repouso, retorno e saída em X não são travados por Y aberto')]
    public function testSoAEntradaETravada(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->x, 'entrada', $this->hojeCedo(2));
        $this->batida($this->x, 'repouso', $this->hojeCedo(3));
        $this->desligarRepousoMinimoEmX();

        foreach (['retorno', 'repouso', 'saida'] as $tipo) {
            self::assertTrue($this->baterEmX($tipo)['success'], $tipo);
        }
    }

    #[TestDox('Interjornada: a saída recente em Y não conta para a entrada em X; a de X conta')]
    public function testInterjornadaNoEscritorio(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->batida($this->y, 'saida', new \DateTime('-1 minute'));
        self::assertTrue($this->baterEmX('entrada')['success'], 'a saída de Y não conta em X');

        $this->batida($this->x, 'saida', new \DateTime('-1 minute'));
        $resposta = $this->baterEmX('entrada');
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Interjornada mínima', $resposta['message']);
    }

    #[TestDox('Trava e interjornada falhando juntas: a trava vem antes')]
    public function testTravaAntesDaInterjornada(): void
    {
        $this->batida($this->x, 'saida', new \DateTime('-1 minute'));
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));

        self::assertSame(sprintf(EntradaAbertaEmOutroEscritorio::MENSAGEM_TRAVA, $this->y->getName()), $this->baterEmX('entrada')['message']);
    }

    #[TestDox('Sem home office em X e sem GPS: a trava responde antes da geolocalização')]
    public function testTravaAntesDoGps(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM home_office_config WHERE user_id = ?', [$this->user->getId()]);
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));

        self::assertSame(sprintf(EntradaAbertaEmOutroEscritorio::MENSAGEM_TRAVA, $this->y->getName()), $this->baterEmX('entrada')['message']);
    }

    #[TestDox('Dentro da área de X (com GPS, sem home office): a mesma trava')]
    public function testDentroDaAreaDeX(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM home_office_config WHERE user_id = ?', [$this->user->getId()]);
        $this->sede($this->x);
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));

        $resposta = $this->baterEmX('entrada', ['latitude' => -23.55, 'longitude' => -46.63, 'precisaoGps' => 10.0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(sprintf(EntradaAbertaEmOutroEscritorio::MENSAGEM_TRAVA, $this->y->getName()), $resposta['message']);
    }

    #[TestDox('Super admin: com vínculo em Y aberto trava (N6); sem vínculo em Y, as batidas de Y não travam')]
    public function testSuperAdmin(): void
    {
        $master = $this->user(['ROLE_SUPER_ADMIN']);
        $this->vincular($master, $this->x, []);
        $this->homeOfficeTodoDia($master, $this->x);
        $this->batida($this->y, 'entrada', $this->hojeCedo(1), $master);   // batida em Y sem vínculo em Y

        $this->logarComTenant($this->client, $master, $this->x);
        self::assertTrue($this->postar('entrada')['success'], 'sem vínculo em Y');

        $this->em()->getConnection()->executeStatement('DELETE FROM registro_ponto WHERE user_id = ? AND tenant_id = ?', [$master->getId(), $this->x->getId()]);
        $this->vincular($master, $this->y, []);
        $this->postar('entrada');
        self::assertResponseStatusCodeSame(422, 'com vínculo em Y');
    }

    #[TestDox('A folha de X depois da recusa não mostra nada de Y')]
    public function testFolhaDeXSemDadoDeY(): void
    {
        $this->batida($this->y, 'entrada', $this->hojeCedo(1));
        $this->baterEmX('entrada');

        $this->client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $this->y->getName(), (string) $this->client->getResponse()->getContent());
    }

    // ----------------------------------------------------------------- apoio

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

    private function desligarRepousoMinimoEmX(): void
    {
        $jornada = $this->x->getJornadaTenant();
        if ($jornada === null) {
            return;
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
