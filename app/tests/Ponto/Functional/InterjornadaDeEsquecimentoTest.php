<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Service\SaidaDeEsquecimento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A interjornada não recusa por saída vinda de esquecimento (spec `docs/specs/ponto-esquecimento-nao-trava.md` R4,
 * risco ALTO), com o EntityManager real e a aprovação de verdade pela rota do admin. A saída batida continua recusando.
 * O escritório tem home office liberado todo dia para a pessoa (a batida passa sem GPS).
 */
#[CoversClass(PontoController::class)]
#[CoversClass(SaidaDeEsquecimento::class)]
final class InterjornadaDeEsquecimentoTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 310) {
            self::markTestSkipped('Antes das 03:10 a saída de 3 horas atrás cairia em ontem.');
        }
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->tenant = $this->tenant();
        $this->user = $this->user(['ROLE_USER']);
        $this->vincular($this->user, ['modules.ponto.view']);
        $config = new HomeOfficeConfig();
        $config->setUser($this->user);
        $config->setTenant($this->tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    #[TestDox('R4: a saída aprovada por esquecimento há 3 horas não recusa a entrada; a batida grava e a resposta traz o aviso da interjornada')]
    public function testSaidaAprovadaPorEsquecimentoViraAviso(): void
    {
        $saida = new \DateTimeImmutable('-3 hours');
        $this->batida('entrada', $saida->modify('-1 minute'));
        $this->aprovarPeloAdmin($this->esquecimentoDaSaida($saida));

        $resposta = $this->bater('entrada');

        self::assertTrue($resposta['success'], (string) ($resposta['message'] ?? ''));
        self::assertSame([[
            'texto' => sprintf(SaidaDeEsquecimento::AVISO_INTERJORNADA, $saida->format('d/m'), $saida->format('H:i'), 11),
            'link' => null,
            'rotulo' => null,
        ]], $resposta['avisos']);
        self::assertSame(2, $this->batidasDoTipo('entrada'));
    }

    #[TestDox('R4: a saída batida há 3 horas continua recusando a entrada pela interjornada, sem gravar')]
    public function testSaidaBatidaContinuaRecusando(): void
    {
        $this->batida('saida', new \DateTimeImmutable('-3 hours'));

        $resposta = $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Interjornada mínima é de 11h.', (string) $resposta['message']);
        self::assertSame(0, $this->batidasDoTipo('entrada'));
    }

    #[TestDox('R4: a observação fixa sem o esquecimento abonado do mesmo dia, batida e hora não basta: continua recusando')]
    public function testObservacaoSemJustificativaNaoBasta(): void
    {
        $saida = new \DateTimeImmutable('-3 hours');
        $this->batida('saida', $saida, SaidaDeEsquecimento::OBSERVACAO_APROVACAO);
        $outraHora = $this->esquecimentoDaSaida($saida->modify('-5 minutes'));
        $outraHora->setStatus('abonado');
        $this->em()->flush();

        $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->batidasDoTipo('entrada'));
    }

    #[TestDox('R4: o esquecimento abonado sem a observação fixa na saída (lançamento manual) não basta: continua recusando')]
    public function testJustificativaSemObservacaoNaoBasta(): void
    {
        $saida = new \DateTimeImmutable('-3 hours');
        $this->batida('saida', $saida, 'Lançado à mão pelo admin');
        $this->esquecimentoDaSaida($saida)->setStatus('abonado');
        $this->em()->flush();

        $this->bater('entrada');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->batidasDoTipo('entrada'));
    }

    // ----------------------------------------------------------------- apoio

    private function esquecimentoDaSaida(\DateTimeImmutable $quando): JustificativaPonto
    {
        $pedido = new JustificativaPonto();
        $pedido->setUser($this->user);
        $pedido->setTenant($this->tenant);
        $pedido->setData(new \DateTime($quando->format('Y-m-d')));
        $pedido->setStatus('pendente');
        $pedido->setTipo(TipoJustificativa::EsquecimentoRegistro->value);
        $pedido->setTipoRegistroEsquecido('saida');
        $pedido->setHoraRegistroEsquecido(new \DateTime($quando->format('H:i:s')));
        $this->em()->persist($pedido);
        $this->em()->flush();

        return $pedido;
    }

    /** A aprovação pela rota do admin, que grava a batida de saída com a observação fixa. */
    private function aprovarPeloAdmin(JustificativaPonto $pedido): void
    {
        $admin = $this->user(['ROLE_SUPER_ADMIN']);
        $vinculo = new UserTenant($admin, $this->tenant);
        $this->em()->persist($vinculo);
        $this->em()->flush();
        $this->logarComTenant($this->client, $admin, $this->tenant);
        $id = (int) $pedido->getId();
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/aprovar', $this->tenant->getId(), $this->user->getId(), $id), [
            '_token' => 'TOKEN_justificativa_aprovar_' . $id,
        ]);
        self::assertResponseRedirects();
        self::assertSame(1, $this->batidasDoTipo('saida'), 'a aprovação gravou a saída');
    }

    /** @return array<string, mixed> */
    private function bater(string $tipo): array
    {
        $this->logarComTenant($this->client, $this->user, $this->tenant);
        $this->client->request(
            'POST',
            '/ponto/batida',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode(['tipo' => $tipo]),
        );

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function batida(string $tipo, \DateTimeImmutable $quando, ?string $observacao = null): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($this->tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(\DateTime::createFromImmutable($quando));
        $registro->setObservacao($observacao);
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function batidasDoTipo(string $tipo): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ? AND tipo = ?',
            [$this->user->getId(), $this->tenant->getId(), $tipo],
        );
    }

    /** @param list<string> $codigos */
    private function vincular(User $user, array $codigos): void
    {
        $em = $this->em();
        $papel = new TenantRole();
        $papel->setTenant($this->tenant);
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
        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório interjornada ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function user(array $papeis): User
    {
        $user = new User();
        $user->setEmail('interjornada_esquecimento_' . uniqid() . '@test.com');
        $user->setFullName('Interjornada de esquecimento');
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
