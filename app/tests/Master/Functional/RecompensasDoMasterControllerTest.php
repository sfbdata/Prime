<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Indicacao\Entity\Indicacao;
use App\Master\Controller\RecompensasDoMasterController;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Seção Recompensas do /master, F1 (desenho 05 VS 25.10.26): leitura, regras, condição individual, as recusas
 * (403 sem token ou sem ser Master, 404, 422) e a auditoria de plataforma. O armazém de CSRF é fixo (padrão do painel).
 */
#[CoversClass(RecompensasDoMasterController::class)]
final class RecompensasDoMasterControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . RecompensasDoMasterController::TOKEN;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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

    #[TestDox('Master lê: regras padrão, KPIs zerados, e o indicador com a conta criada (sem dado do indicado)')]
    public function testLe(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->client->request('GET', '/master/recompensas');

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(['ativo' => true, 'valorCentavos' => 1000, 'minEscritorios' => 10, 'carenciaDias' => 30, 'alertaQtd' => 5, 'exigePasta' => true, 'comCondicaoIndividual' => 0], $d['regras']);
        $linha = $this->linhaDe($d, (int) $ana->getId());
        self::assertSame(1, $linha['contas']);
        self::assertSame(0, $linha['ativos'], 'a conta não tem pasta e o programa exige');
        self::assertSame('andamento', $linha['situacao']);
        self::assertArrayNotHasKey('indicado', $linha);
        self::assertStringNotContainsString('conta_nova', (string) $this->client->getResponse()->getContent(), 'nada do e-mail do indicado');
        self::assertSame(0, $d['kpis']['jaPagoCentavos']);
    }

    #[TestDox('quem não é Master recebe 403 em toda rota e nada muda')]
    public function testNaoMaster(): void
    {
        $ana = $this->indicadoraComContaAntiga();
        $this->entrarComoPrimarioDeEscritorio();

        $this->client->request('GET', '/master/recompensas');
        self::assertResponseStatusCodeSame(403);

        $this->enviar('POST', '/master/recompensas/regras', $this->regrasValidas());
        self::assertResponseStatusCodeSame(403);
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00']);
        self::assertResponseStatusCodeSame(403);
        $this->enviar('DELETE', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao');
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNull(static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico());
        self::assertNull(static::getContainer()->get(CondicaoDeRecompensaRepository::class)->buscarDoIndicador((int) $ana->getId()));
    }

    #[TestDox('CSRF errado ou ausente: 403 e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->enviar('POST', '/master/recompensas/regras', $this->regrasValidas(), 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00'], '');
        self::assertResponseStatusCodeSame(403);
        $this->enviar('DELETE', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', null, 'errado');
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertNull(static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico());
    }

    #[TestDox('regras: grava as seis, devolve o estado novo e deixa a trilha de auditoria de plataforma (sem escritório)')]
    public function testAlteraRegras(): void
    {
        $master = $this->entrarComoMaster();

        $this->enviar('POST', '/master/recompensas/regras', ['ativo' => false, 'valor' => '25,50', 'minEscritorios' => '3', 'carenciaDias' => 0, 'alertaQtd' => 8, 'exigePasta' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ativo' => false, 'valorCentavos' => 2550, 'minEscritorios' => 3, 'carenciaDias' => 0, 'alertaQtd' => 8, 'exigePasta' => false, 'comCondicaoIndividual' => 0], $this->json()['regras']);
        $this->em->clear();
        $linha = static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico();
        self::assertNotNull($linha);
        self::assertSame($master->getId(), $linha->getAlteradoPor()?->getId());
        $auditoria = $this->em->getConnection()->fetchFirstColumn('SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [ProgramaDeRecompensa::class, $master->getId()]);
        self::assertNotEmpty($auditoria, 'a gravação entra no audit_log');
        self::assertSame([null], array_values(array_unique($auditoria)), 'no escopo de plataforma, sem escritório');
    }

    #[TestDox('regras recusadas: 422 com a mensagem e a linha não muda (valor negativo, texto, "libera com" zero, regra faltando)')]
    public function testRegrasRecusadas(): void
    {
        $this->entrarComoMaster();

        foreach ([
            ['valor' => '-5'] + $this->regrasValidas(),
            ['valor' => 'dez'] + $this->regrasValidas(),
            ['minEscritorios' => '0'] + $this->regrasValidas(),
            ['minEscritorios' => '1,5'] + $this->regrasValidas(),
            ['carenciaDias' => '-1'] + $this->regrasValidas(),
            ['alertaQtd' => 0] + $this->regrasValidas(),
            ['valor' => ''] + $this->regrasValidas(),
            ['ativo' => 'sim'] + $this->regrasValidas(),
        ] as $i => $corpo) {
            $this->enviar('POST', '/master/recompensas/regras', $corpo);
            self::assertResponseStatusCodeSame(422, 'caso ' . $i);
            self::assertNotSame('', $this->json()['erro'], 'caso ' . $i);
        }
        $this->em->clear();
        self::assertNull(static::getContainer()->get(ProgramaDeRecompensaRepository::class)->buscarUnico());
    }

    #[TestDox('condição individual: grava, aparece na linha e no "N com condição individual", vale na leitura e vai para a auditoria')]
    public function testDefineCondicao(): void
    {
        $master = $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();

        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00', 'minEscritorios' => '1', 'carenciaDias' => '', 'recorrente' => true, 'mensal' => '2,00', 'motivo' => 'Convênio com a seccional']);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(1, $d['regras']['comCondicaoIndividual']);
        $linha = $this->linhaDe($d, (int) $ana->getId());
        self::assertSame(['valorCentavos' => 3000, 'minEscritorios' => 1, 'carenciaDias' => null, 'recorrente' => true, 'mensalCentavos' => 200, 'motivo' => 'Convênio com a seccional'], $linha['condicao']);
        self::assertSame(3000, $linha['valorCentavos']);
        self::assertSame(1, $linha['minEscritorios']);
        $this->em->clear();
        $auditoria = $this->em->getConnection()->fetchFirstColumn('SELECT tenant_id FROM audit_log WHERE entity_class = ? AND actor_user_id = ?', [CondicaoDeRecompensa::class, $master->getId()]);
        self::assertNotEmpty($auditoria);
        self::assertSame([null], array_values(array_unique($auditoria)));
    }

    #[TestDox('voltar à regra geral: remove a condição, devolve o estado novo e audita a remoção')]
    public function testRemoveCondicao(): void
    {
        $master = $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', ['valor' => '30,00']);
        self::assertResponseIsSuccessful();

        $this->enviar('DELETE', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao');

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(0, $d['regras']['comCondicaoIndividual']);
        self::assertNull($this->linhaDe($d, (int) $ana->getId())['condicao']);
        $this->em->clear();
        self::assertNull(static::getContainer()->get(CondicaoDeRecompensaRepository::class)->buscarDoIndicador((int) $ana->getId()));
        $acoes = $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log WHERE entity_class = ? AND actor_user_id = ? ORDER BY id', [CondicaoDeRecompensa::class, $master->getId()]);
        self::assertSame(['create', 'delete'], array_map('strtolower', $acoes));
    }

    #[TestDox('condição recusada: 422 (valor negativo, recorrente sem mensal) e 404 para usuário que nunca indicou; nada é gravado')]
    public function testCondicaoRecusada(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraComContaAntiga();
        $semIndicacao = $this->usuario(['ROLE_USER']);

        foreach ([['valor' => '-1'], ['valor' => 'x'], ['recorrente' => true], ['minEscritorios' => '0'], ['motivo' => str_repeat('a', 256)]] as $i => $corpo) {
            $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao', $corpo);
            self::assertResponseStatusCodeSame(422, 'caso ' . $i);
            self::assertArrayHasKey('erro', $this->json());
        }
        $this->enviar('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/condicao');
        self::assertResponseStatusCodeSame(422, 'corpo que não é JSON');
        $this->enviar('POST', '/master/recompensas/usuarios/' . $semIndicacao->getId() . '/condicao', ['valor' => '30,00']);
        self::assertResponseStatusCodeSame(404);
        $this->enviar('POST', '/master/recompensas/usuarios/999999999/condicao', ['valor' => '30,00']);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertSame([], static::getContainer()->get(CondicaoDeRecompensaRepository::class)->findAll());
    }

    #[TestDox('o painel traz a seção Recompensas como externa (data-sec-externa="recomp") com o contêiner, o CSS e o script')]
    public function testPainel(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('div.mp-secao-externa[data-sec-externa="recomp"] > div.mrc[data-mrc]'));
        self::assertCount(1, $crawler->filter('script[src*="js/master/recompensas.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/master-recompensas.css"]'));
        self::assertNotSame('', (string) $crawler->filter('[data-mrc]')->attr('data-token'));
    }

    /** @return array<string, mixed> */
    private function regrasValidas(): array
    {
        return ['ativo' => true, 'valor' => '10,00', 'minEscritorios' => 10, 'carenciaDias' => 30, 'alertaQtd' => 5, 'exigePasta' => true];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function linhaDe(array $dados, int $userId): array
    {
        foreach ($dados['indicadores'] as $linha) {
            if ($linha['userId'] === $userId) {
                return $linha;
            }
        }
        self::fail('indicador ' . $userId . ' fora da lista');
    }

    /** Indicadora (e-mail) cuja conta nova foi criada há 60 dias, sem pasta no escritório. */
    private function indicadoraComContaAntiga(): User
    {
        $ana = $this->usuario(['ROLE_USER']);
        $nova = $this->usuario(['ROLE_USER'], 'conta_nova_' . uniqid() . '@teste.com');
        $nova->setCreatedAt(new \DateTimeImmutable('-60 days'));
        $this->em->persist(Indicacao::porEmail($ana, null, $nova->getEmail(), null, new \DateTimeImmutable('-70 days')));
        $this->em->flush();

        return $ana;
    }

    private function entrarComoMaster(): User
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = (new Tenant())->setName('Escritório das recompensas ' . uniqid());
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, ?string $email = null): User
    {
        $u = new User();
        $u->setEmail($email ?? 'recompensas_' . uniqid() . '@teste.com');
        $u->setFullName('Usuária das Recompensas');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed>|null $corpo */
    private function enviar(string $metodo, string $url, ?array $corpo = null, string $token = self::TOKEN): void
    {
        $this->client->request($metodo, $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: $corpo === null ? '' : (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 12, \JSON_THROW_ON_ERROR);
    }
}
