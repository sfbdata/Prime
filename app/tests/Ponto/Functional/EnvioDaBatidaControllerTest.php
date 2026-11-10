<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Etapa E13 do desenho novo do Ponto (spec ponto-desenho-0811 §5): a batida guarda a hora do aparelho e a hora em que
 * chegou ao servidor, e o mesmo identificador de envio nunca cria duas batidas. A hora oficial continua sendo a do
 * servidor. Sem os campos novos, a batida segue como antes (o atalho antigo e a página em cache).
 *
 * A pessoa bate em home office liberado todos os dias: a cerca não entra na conta destes testes.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
final class EnvioDaBatidaControllerTest extends JusPrimeWebTestCase
{
    private const ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->tenant = $this->criarTenant();
        $this->user = $this->criarUsuario($this->tenant);
        $this->liberarHomeOfficeTodosOsDias($this->user, $this->tenant);
        $this->logarComTenant($this->client, $this->user, $this->tenant);
    }

    #[TestDox('A batida grava a hora do aparelho como veio, a chegada e o momento igual à chegada')]
    public function testGravaAsDuasHoras(): void
    {
        $aparelhoMs = (time() - 40) * 1000 + 123;   // o relógio do aparelho 40 s atrasado
        $antes = new \DateTimeImmutable('-1 second');

        $resposta = $this->postarBatida(['tipo' => 'entrada', 'idEnvio' => strtoupper(self::ID), 'horaAparelho' => $aparelhoMs]);

        $depois = new \DateTimeImmutable('+1 second');
        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['success']);
        $registro = $this->unicaBatida();
        self::assertNotNull($registro->getHoraChegada());
        self::assertSame($registro->getHoraChegada()->format('Y-m-d H:i:s'), $registro->getDataHora()->format('Y-m-d H:i:s'), 'a hora oficial é a da chegada');
        self::assertGreaterThanOrEqual($antes->getTimestamp(), $registro->getHoraChegada()->getTimestamp());
        self::assertLessThanOrEqual($depois->getTimestamp(), $registro->getHoraChegada()->getTimestamp());
        self::assertSame(intdiv($aparelhoMs, 1000), $registro->getHoraAparelho()?->getTimestamp(), 'a hora do aparelho fica só como informação');
        self::assertSame(self::ID, $registro->getIdEnvio(), 'o identificador fica em minúsculas');
        self::assertFalse($registro->isEnviadaSemInternet());
        self::assertSame($registro->getDataHora()->format('H:i:s'), $resposta['data']['hora']);
    }

    #[TestDox('O mesmo identificador enviado duas vezes cria UMA batida e a segunda responde "já registrada"')]
    public function testMesmoEnvioDuasVezesCriaUmaBatida(): void
    {
        $primeira = $this->postarBatida(['tipo' => 'entrada', 'idEnvio' => self::ID]);
        $segunda = $this->postarBatida(['tipo' => 'entrada', 'idEnvio' => self::ID]);

        self::assertResponseIsSuccessful();
        $registro = $this->unicaBatida();
        self::assertSame([
            'success' => true,
            'message' => 'Esta batida já tinha sido registrada.',
            'data' => ['hora' => $registro->getDataHora()->format('H:i:s'), 'tipo' => 'entrada', 'jaRegistrada' => true],
            'avisos' => [],
        ], $segunda);
        self::assertSame($primeira['data']['hora'], $segunda['data']['hora']);
    }

    #[TestDox('A nova tentativa responde "já registrada" antes das regras: sem GPS e sem home office, não recusa')]
    public function testJaRegistradaAntesDasRegras(): void
    {
        $existente = $this->criarBatida($this->tenant, RegistroPonto::TIPO_SAIDA, self::ID);
        $this->em()->createQuery('DELETE FROM App\Ponto\Entity\HomeOfficeConfig c WHERE c.user = :u')->setParameter('u', $this->user)->execute();

        $resposta = $this->postarBatida(['tipo' => 'saida', 'idEnvio' => self::ID]);

        self::assertResponseIsSuccessful('sem GPS a batida seria recusada; a que já entrou responde antes da cerca');
        self::assertSame(['hora' => $existente->getDataHora()->format('H:i:s'), 'tipo' => 'saida', 'jaRegistrada' => true], $resposta['data']);
        self::assertCount(1, $this->batidasDoUsuario());
    }

    #[TestDox('Dois pedidos do mesmo envio ao mesmo tempo: o índice único barra o segundo, que responde "já registrada" sem a hora')]
    public function testIndiceUnicoBarraOSegundoPedido(): void
    {
        // O envio já entrou em outro escritório da pessoa: a consulta daqui não o vê, como o pedido que perdeu a corrida.
        $outro = $this->criarTenant();
        $this->criarBatida($outro, RegistroPonto::TIPO_ENTRADA, self::ID);

        $resposta = $this->postarBatida(['tipo' => 'entrada', 'idEnvio' => self::ID]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'success' => true,
            'message' => 'Esta batida já tinha sido registrada.',
            'data' => ['tipo' => 'entrada', 'jaRegistrada' => true],
            'avisos' => [],
        ], $resposta);
        $conexao = $this->em()->getConnection();
        self::assertSame(1, (int) $conexao->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE user_id = ?', [$this->user->getId()]), 'nada entrou aqui');
    }

    #[TestDox('O mesmo identificador usado por OUTRA pessoa do escritório grava normal e não responde "já registrada"')]
    public function testMesmoEnvioDeOutraPessoaGravaNormal(): void
    {
        $outraPessoa = $this->criarUsuario($this->tenant);
        $daOutra = new RegistroPonto();
        $daOutra->setUser($outraPessoa);
        $daOutra->setTenant($this->tenant);
        $daOutra->setTipo(RegistroPonto::TIPO_ENTRADA);
        $daOutra->setDataHora(new \DateTime('-2 hours'));
        $daOutra->setIdEnvio(self::ID);
        $this->em()->persist($daOutra);
        $this->em()->flush();

        $resposta = $this->postarBatida(['tipo' => 'entrada', 'idEnvio' => self::ID]);

        self::assertResponseIsSuccessful();
        self::assertSame('Ponto (home office) registrado com sucesso!', $resposta['message']);
        self::assertArrayNotHasKey('jaRegistrada', $resposta['data']);
        $registro = $this->unicaBatida();
        self::assertSame(self::ID, $registro->getIdEnvio(), 'o índice único é por pessoa');
        self::assertSame($registro->getDataHora()->format('H:i:s'), $resposta['data']['hora']);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE id_envio = ?', [self::ID]), 'a da outra pessoa continua lá');
    }

    #[TestDox('Identificador fora do formato recusa com 422 e não grava')]
    public function testIdentificadorInvalidoRecusa(): void
    {
        $resposta = $this->postarBatida(['tipo' => 'entrada', 'idEnvio' => 'nao-e-um-uuid']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['success' => false, 'message' => 'Identificador do envio inválido.'], $resposta);
        self::assertCount(0, $this->batidasDoUsuario());
    }

    #[TestDox('Hora do aparelho inválida na batida normal vira null e a batida entra')]
    public function testHoraDoAparelhoInvalidaNaoRecusa(): void
    {
        $this->postarBatida(['tipo' => 'entrada', 'idEnvio' => self::ID, 'horaAparelho' => 'agora']);

        self::assertResponseIsSuccessful();
        self::assertNull($this->unicaBatida()->getHoraAparelho());
    }

    #[TestDox('Sem os campos novos tudo igual a hoje: a resposta de sempre, sem identificador nem hora do aparelho')]
    public function testSemOsCamposNovosTudoIgual(): void
    {
        $resposta = $this->postarBatida(['tipo' => 'entrada']);

        self::assertResponseIsSuccessful();
        self::assertSame(['success', 'message', 'data', 'avisos'], array_keys($resposta));
        self::assertSame('Ponto (home office) registrado com sucesso!', $resposta['message']);
        self::assertSame(['hora', 'tipo'], array_keys($resposta['data']));
        $registro = $this->unicaBatida();
        self::assertNull($registro->getIdEnvio());
        self::assertNull($registro->getHoraAparelho());
        self::assertFalse($registro->isEnviadaSemInternet());
        self::assertSame($registro->getDataHora()->format('H:i:s'), $resposta['data']['hora']);
    }

    #[TestDox('Batidas sem identificador continuam entrando várias vezes (NULL repete no índice único)')]
    public function testSemIdentificadorRepete(): void
    {
        $this->postarBatida(['tipo' => 'entrada']);
        $this->postarBatida(['tipo' => 'saida']);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->batidasDoUsuario());
    }

    // ----------------------------------------------------------------- apoio

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postarBatida(array $payload): array
    {
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

    private function criarBatida(Tenant $tenant, string $tipo, ?string $idEnvio): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime('-2 hours'));
        $registro->setIdEnvio($idEnvio);
        $this->em()->persist($registro);
        $this->em()->flush();

        return $registro;
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
        $tenant->setName('Envio da batida ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail('envio_batida_' . uniqid() . '@test.com');
        $user->setFullName('Envio da batida');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function liberarHomeOfficeTodosOsDias(User $user, Tenant $tenant): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($user);
        $config->setTenant($tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
