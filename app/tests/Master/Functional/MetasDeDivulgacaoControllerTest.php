<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\MetasDeDivulgacaoController;
use App\Master\Entity\MetaDeDivulgacao;
use App\Master\Repository\MetaDeDivulgacaoRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Bloco "Metas de divulgação" da Visão geral (desenho 05 VS 25.10.26, P3): leitura, as ações, a recusa e o acesso só
 * do Master. O armazém de CSRF é fixo (padrão dos testes do painel).
 */
#[CoversClass(MetasDeDivulgacaoController::class)]
final class MetasDeDivulgacaoControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . MetasDeDivulgacaoController::CSRF;

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

    #[TestDox('Master lê as metas: padrões do desenho, destaques e metas vazios, link do Quadro')]
    public function testLe(): void
    {
        $this->entrarComoMaster();

        $this->client->request('GET', '/master/metas-divulgacao');

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(1000, $d['alvo']);
        self::assertTrue($d['automatica']);
        self::assertSame('mensal', $d['frequencia']);
        self::assertStringStartsWith('https://', $d['urlDoQuadro']);
        self::assertArrayHasKey('advogadosPorUf', $d);
    }

    #[TestDox('quem não é Master recebe 403 e nada muda')]
    public function testNaoMaster(): void
    {
        $this->entrarComoPrimarioDeEscritorio();

        $this->client->request('GET', '/master/metas-divulgacao');
        self::assertResponseStatusCodeSame(403);

        $this->postar(['acao' => 'alvo', 'valor' => 5]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravada());
    }

    #[TestDox('CSRF errado: 403 e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();

        $this->postar(['acao' => 'alvo', 'valor' => 5], 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravada());
    }

    #[TestDox('alterar: meta, ajuste manual, estado, cidade e destaque gravam e voltam no estado novo')]
    public function testAltera(): void
    {
        $master = $this->entrarComoMaster();

        $this->postar(['acao' => 'alvo', 'valor' => 10000]);
        self::assertResponseIsSuccessful();
        self::assertSame(10000, $this->json()['alvo']);

        $this->postar(['acao' => 'ajustar', 'mercado' => 2000000, 'valor' => 20000]);
        self::assertTrue($this->json()['mercadoManual']);
        self::assertSame(2000000, $this->json()['mercado']);

        $this->postar(['acao' => 'meta_uf', 'uf' => 'SP', 'valor' => 300]);
        $this->postar(['acao' => 'meta_cidade', 'uf' => 'SP', 'cidade' => 'Campinas', 'valor' => 40]);
        $this->postar(['acao' => 'destaque', 'uf' => 'SP', 'texto' => '#2e86de']);
        $d = $this->json();
        self::assertSame(['SP' => 300], $d['metasPorUf']);
        self::assertSame(['SP' => ['Campinas' => 40]], $d['metasPorCidade']);
        self::assertSame(['SP' => '#2e86de'], $d['destaques']);

        $this->postar(['acao' => 'frequencia', 'texto' => 'mensal']);
        $this->postar(['acao' => 'fase', 'ligado' => true]);
        $this->postar(['acao' => 'voltar_automatico']);
        $d = $this->json();
        self::assertSame('mensal', $d['frequencia']);
        self::assertTrue($d['avancarDeFase']);
        self::assertFalse($d['mercadoManual']);

        $this->em->clear();
        $linha = $this->gravada();
        self::assertNotNull($linha);
        self::assertSame($master->getId(), $linha->getAlteradoPor()?->getId());
    }

    #[TestDox('pedido recusado: 422 com a mensagem, e a linha não muda')]
    public function testRecusa(): void
    {
        $this->entrarComoMaster();

        foreach ([
            ['acao' => 'alvo', 'valor' => 0],
            ['acao' => 'frequencia', 'texto' => 'diaria'],
            ['acao' => 'meta_uf', 'uf' => 'XX', 'valor' => 5],
            ['acao' => 'destaque', 'uf' => 'SP', 'texto' => '#000000'],
            ['acao' => 'inexistente'],
        ] as $corpo) {
            $this->postar($corpo);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('erro', $this->json());
        }
        self::assertNull($this->gravada());
    }

    #[TestDox('o painel traz o contêiner e o script das metas')]
    public function testPainel(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('script[src*="js/master/metas-divulgacao.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/master-metas-divulgacao.css"]'));
    }

    private function gravada(): ?MetaDeDivulgacao
    {
        return static::getContainer()->get(MetaDeDivulgacaoRepository::class)->buscarUnica();
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
        $tenant = (new Tenant())->setName('Escritório das metas ' . uniqid());
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
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('metas_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário das Metas');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', '/master/metas-divulgacao', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }
}
