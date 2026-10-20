<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Controller\PastaController;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Expediente\Service\CoresDoMarcador;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Criar marcador" dentro do modal Marcadores da pasta (desenho VS 18.10.26, dc L.461 e
 * `marcVals` L.3833). O modal cria pela rota que já existe (`expediente_marcador_criar`), que
 * exige o módulo Expediente e o CSRF `marcador`:
 *
 *  · a tela só entrega a URL e o token de criar a quem tem o módulo (sem ele, a linha não aparece);
 *  · a rota cria no escritório da sessão e recusa sem CSRF e sem o módulo.
 */
#[CoversClass(ExpedienteController::class)]
#[CoversClass(PastaController::class)]
#[Group('expediente')]
final class CriarMarcadorPeloModalTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const COM_EXPEDIENTE = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view'];
    private const SEM_EXPEDIENTE = ['modules.pastas.view', 'resources.pasta.view'];

    #[TestDox('com o módulo Expediente, o modal da pasta recebe a URL, o token e a paleta para criar')]
    public function testModalOfereceCriarComPermissao(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $usuario = $this->criarUsuarioComPermissoes($tenant, self::COM_EXPEDIENTE);
        $this->logarComTenant($client, $usuario, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $modal = $crawler->filter('#modalMoverPara');
        self::assertCount(1, $modal);
        self::assertSame('/expediente/marcador', $modal->attr('data-url-criar-marcador'));
        self::assertNotEmpty((string) $modal->attr('data-csrf-criar-marcador'));
        self::assertSame(CoresDoMarcador::DESENHO, json_decode((string) $modal->attr('data-cores-marcador'), true));
    }

    #[TestDox('sem o módulo Expediente, o modal não recebe nada de criar (a linha "Criar marcador" não aparece)')]
    public function testModalNaoOfereceCriarSemPermissao(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $usuario = $this->criarUsuarioComPermissoes($tenant, self::SEM_EXPEDIENTE);
        $this->logarComTenant($client, $usuario, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $modal = $crawler->filter('#modalMoverPara');
        self::assertCount(1, $modal, 'premissa: o modal existe para quem vê a pasta');
        self::assertNull($modal->attr('data-url-criar-marcador'));
        self::assertNull($modal->attr('data-csrf-criar-marcador'));
    }

    #[TestDox('o POST do modal (nome + cor da paleta + CSRF) cria o marcador no escritório da sessão')]
    public function testCriaComCsrf(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuarioComPermissoes($tenant, self::COM_EXPEDIENTE);
        $this->logarComTenant($client, $usuario, $tenant);

        $client->xmlHttpRequest('POST', '/expediente/marcador', [
            '_token' => 'TOKEN_marcador',
            'nome'   => 'Urgente do modal',
            'cor'    => CoresDoMarcador::DESENHO[0],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Urgente do modal', $data['nome']);
        self::assertSame(CoresDoMarcador::DESENHO[0], $data['cor']);
        self::assertNull($data['paiId']);

        $this->em()->clear();
        $gravado = $this->em()->getRepository(Marcador::class)->find($data['id']);
        self::assertNotNull($gravado);
        self::assertSame($tenant->getId(), $gravado->getTenant()?->getId());
    }

    #[TestDox('sem CSRF válido: 403 e nada é criado')]
    public function testRecusaSemCsrf(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuarioComPermissoes($tenant, self::COM_EXPEDIENTE);
        $this->logarComTenant($client, $usuario, $tenant);

        $client->xmlHttpRequest('POST', '/expediente/marcador', ['_token' => 'errado', 'nome' => 'Sem token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(Marcador::class)->count(['tenant' => $tenant]));
    }

    #[TestDox('sem o módulo Expediente: 403 mesmo com CSRF válido, e nada é criado')]
    public function testRecusaSemPermissao(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuarioComPermissoes($tenant, self::SEM_EXPEDIENTE);
        $this->logarComTenant($client, $usuario, $tenant);

        $client->xmlHttpRequest('POST', '/expediente/marcador', ['_token' => 'TOKEN_marcador', 'nome' => 'Sem módulo']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(Marcador::class)->count(['tenant' => $tenant]));
    }

    /** TokenStorage em memória com tokens previsíveis (o mesmo do ExpedienteMarcadoresRespostaJsonTest). */
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
