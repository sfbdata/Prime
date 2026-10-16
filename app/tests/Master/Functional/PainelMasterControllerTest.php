<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\FontesPublicasController;
use App\Master\Controller\MapaMasterController;
use App\Master\Controller\PainelMasterController;
use App\Master\Controller\PainelMasterDadosController;
use App\Master\Fontes\ArmazemDeFontes;
use App\Master\Fontes\ClienteIbge;
use App\Master\Fontes\ClienteQuadroOab;
use App\Master\Fontes\FontesPublicas;
use App\Master\Fontes\LeitorQuadroOab;
use App\Master\Fontes\SementeQuadroOab;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Guarda e contrato HTTP do Painel do Master (spec perfis-master §6): tudo em /master/* é só do
 * Master (ROLE_SUPER_ADMIN); primário e secundário de escritório recebem 403. As fontes públicas
 * são servidas com rede falsa (MockHttpClient): nenhum teste toca IBGE ou OAB de verdade.
 */
#[CoversClass(PainelMasterController::class)]
#[CoversClass(PainelMasterDadosController::class)]
#[CoversClass(MapaMasterController::class)]
#[CoversClass(FontesPublicasController::class)]
final class PainelMasterControllerTest extends JusPrimeWebTestCase
{
    private const GET = [
        '/master',
        '/master/dados/visao-geral',
        '/master/dados/contas',
        '/master/dados/usuarios-oab',
        '/master/dados/auditoria',
        '/master/dados/mapa',
        '/master/mapa/brasil',
        '/master/mapa/mundo',
        '/master/fontes',
        '/master/ibge/municipios/AL',
        '/master/ibge/populacao/AL',
    ];

    private const POST = [
        '/master/fontes/atualizar',
        '/master/fontes/tabela',
        '/master/fontes/oab',
    ];

    private bool $redeFora = false;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rotas(): iterable
    {
        foreach (self::GET as $url) {
            yield 'GET ' . $url => ['GET', $url];
        }
        foreach (self::POST as $url) {
            yield 'POST ' . $url => ['POST', $url];
        }
    }

    #[TestDox('Master acessa o painel, os dados, os mapas e as fontes públicas (200)')]
    public function testMasterAcessaTudo(): void
    {
        $client = $this->clienteMaster();

        foreach (self::GET as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(200, $url);
        }
    }

    #[DataProvider('rotas')]
    #[TestDox('Primário de escritório recebe 403 em $metodo $url')]
    public function testPrimarioRecebe403(string $metodo, string $url): void
    {
        $client = $this->clienteDeEscritorio(true);
        $this->requisitarComCsrfValido($client, $metodo, $url);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('rotas')]
    #[TestDox('Secundário de escritório recebe 403 em $metodo $url')]
    public function testSecundarioRecebe403(string $metodo, string $url): void
    {
        $client = $this->clienteDeEscritorio(false);
        $this->requisitarComCsrfValido($client, $metodo, $url);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('O token usado nos 403 é válido: com ele o Master passa no CSRF de todos os POST')]
    public function testTokenDosTestesEhValido(): void
    {
        $client = $this->clienteMaster();

        foreach (self::POST as $url) {
            $this->requisitarComCsrfValido($client, 'POST', $url);
            self::assertNotSame(403, $client->getResponse()->getStatusCode(), $url . ' recusou o token: o 403 de primário/secundário provaria só o CSRF');
        }
    }

    #[TestDox('Lotação e cidade do escritório com HTML não saem com < no agregado do mapa')]
    public function testMapaSemHtml(): void
    {
        $client = $this->clienteMaster();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório XSS ' . uniqid())->setEstado('DF')->setCidade('"><script>alert(1)</script>');
        $em->persist($tenant);
        $lotacao = (new Lotacao())->setNome('<img src=x onerror=alert(1)>/SP')->setTenant($tenant);
        $em->persist($lotacao);
        $pessoa = $this->usuario(['ROLE_USER']);
        $vinculo = new UserTenant($pessoa, $tenant);
        $vinculo->setLotacao($lotacao);
        $em->persist($vinculo);
        $em->flush();

        $client->request('GET', '/master/dados/mapa');
        self::assertResponseIsSuccessful();
        $mapa = json_decode((string) $client->getResponse()->getContent(), true);
        $nomes = array_merge(
            ...array_map('array_keys', array_values($mapa['pessoas']['geral']['C'])),
            ...array_map('array_keys', array_values($mapa['escritorios']['C'])),
        );
        self::assertNotEmpty($nomes);
        foreach ($nomes as $nome) {
            self::assertDoesNotMatchRegularExpression('/[<>"\'`]/', (string) $nome);
        }
    }

    #[TestDox('Painel e mapas saem com Content-Security-Policy; os mapas sem script inline')]
    public function testCsp(): void
    {
        $client = $this->clienteMaster();

        $client->request('GET', '/master');
        self::assertStringContainsString("object-src 'none'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("connect-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));

        foreach (['/master/mapa/brasil', '/master/mapa/mundo'] as $url) {
            $client->request('GET', $url);
            $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
            self::assertStringContainsString("script-src 'self';", $csp, $url);
            self::assertStringNotContainsString('unsafe-inline\' https', $csp);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', (string) $client->getResponse()->getContent(), $url . ' tem script inline');
        }

        $client->request('GET', '/master/dados/visao-geral');
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    #[TestDox('Anônimo vai para o login')]
    public function testAnonimoVaiParaLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/master/dados/visao-geral');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('/admin/platform virou o Painel do Master: redireciona para /master')]
    public function testAdminPlatformRedireciona(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/admin/platform');

        self::assertResponseRedirects('/master');
    }

    #[TestDox('Aba Permissões: Master único com cadeado, sem "Promover a Master" nem Master secundário (D2)')]
    public function testAbaPermissoes(): void
    {
        $client = $this->clienteMaster($master);
        $crawler = $client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Editar Perfil: Master do Sistema', $html);
        self::assertStringContainsString('Painel do sistema', $html);
        self::assertStringContainsString($master->getEmail(), $html);
        self::assertStringContainsString('MASTER PRIMÁRIO', $html);
        self::assertStringNotContainsString('Promover a Master', $html);
        self::assertStringNotContainsString('Master secundário', $html);
        self::assertStringNotContainsString('Masters secundários', $html);
        self::assertCount(7, $crawler->filter('.mp-poderes .mp-poder'), 'sem o item "Promover e remover Masters secundários"');
        self::assertSame('/dashboard', $crawler->filter('a.mp-btn-voltar')->attr('href'), 'Master sem escritório volta ao dashboard');
        self::assertStringNotContainsString('—', $html);
        self::assertStringNotContainsString('–', $html);
    }

    #[TestDox('Voltar leva à lista de perfis do escritório atual quando o Master está num escritório')]
    public function testVoltarComEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório do Master ' . uniqid());
        $em->persist($tenant);
        $master = $this->usuario(['ROLE_SUPER_ADMIN']);
        $em->persist(new UserTenant($master, $tenant));
        $em->flush();
        $this->logarComTenant($client, $master, $tenant);

        $crawler = $client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        self::assertSame('/tenant/' . $tenant->getId() . '/roles', $crawler->filter('a.mp-btn-voltar')->attr('href'));
    }

    #[TestDox('JSON da visão geral não leva e-mail de ninguém')]
    public function testVisaoGeralSemEmail(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/dados/visao-geral');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('@', (string) $client->getResponse()->getContent());
    }

    #[TestDox('IBGE fora do ar e sem retrato: 503 com a mensagem do desenho')]
    public function testMunicipiosIbgeFora(): void
    {
        $client = $this->clienteMaster();
        $this->redeFora = true;
        $client->request('GET', '/master/ibge/municipios/SP');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('Não foi possível carregar os municípios do IBGE agora.', json_decode((string) $client->getResponse()->getContent(), true)['erro']);

        $client->request('GET', '/master/ibge/municipios/ZZ');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Atualizar OAB por arquivo (CSV): exige CSRF e grava o retrato com a data do envio')]
    public function testEnviarQuadroOab(): void
    {
        $client = $this->clienteMaster();
        $crawler = $client->request('GET', '/master/mapa/brasil');
        $cfg = json_decode((string) $crawler->filter('body')->attr('data-cfg'), true);

        $client->request('POST', '/master/fontes/oab', ['_token' => 'errado'], ['arquivo' => $this->csv("SECCIONAL;Advogado(a)\nSP;400000\n")]);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/master/fontes/oab', ['_token' => $cfg['csrf']], ['arquivo' => $this->csv("SECCIONAL;Advogado(a)\nSP;400000\n")]);
        self::assertResponseIsSuccessful();
        $resposta = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $resposta['resultado']['seccionais']);
        self::assertSame(400000, $resposta['resumo']['oab']['uf']['SP'][0]);
        self::assertSame(SementeQuadroOab::UF['SP'][1], $resposta['resumo']['oab']['uf']['SP'][1], 'coluna ausente mantém o anterior');
        self::assertSame('16/10/2026', $resposta['resumo']['oab']['data']);

        $client->request('POST', '/master/fontes/oab', ['_token' => $cfg['csrf']], ['arquivo' => $this->csv("SECCIONAL;Advogado(a)\nSP;0\n")]);
        self::assertResponseStatusCodeSame(422, 'advogados zerados: planilha recusada');
        $client->request('POST', '/master/fontes/oab', ['_token' => $cfg['csrf']], ['arquivo' => $this->csv("SECCIONAL;Advogado(a)\nSP;500000\nTOTAL;123\n")]);
        self::assertResponseStatusCodeSame(422, 'TOTAL que não fecha: planilha recusada');
        self::assertStringContainsString('retrato anterior foi mantido', (string) $client->getResponse()->getContent());
        $client->request('GET', '/master/fontes');
        self::assertSame(400000, json_decode((string) $client->getResponse()->getContent(), true)['oab']['uf']['SP'][0], 'retrato anterior mantido');

        $client->request('POST', '/master/fontes/oab', ['_token' => $cfg['csrf']], ['arquivo' => $this->csv("SECCIONAL;Advogado(a)\n" . str_repeat("SP;1\n", 450000))]);
        self::assertResponseStatusCodeSame(422, 'arquivo acima de 2 MB é recusado antes de ser lido');

        $client->request('POST', '/master/fontes/tabela', ['_token' => $cfg['csrf']], ['arquivo' => $this->csv("a;b\n1;2\n")]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Tabela não reconhecida. Use o modelo baixado pelo menu.', json_decode((string) $client->getResponse()->getContent(), true)['erro']);
    }

    private function requisitarComCsrfValido(KernelBrowser $client, string $metodo, string $url): void
    {
        if ($metodo !== 'POST') {
            $client->request($metodo, $url);

            return;
        }

        $client->request('POST', $url, ['_token' => 'TOKEN_' . FontesPublicasController::CSRF], ['arquivo' => $this->csv("SECCIONAL;Advogado(a)\nSP;400000\n")]);
    }

    private function clienteMaster(?User &$master = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarFontesFalsas();
        $master = $this->usuario(['ROLE_SUPER_ADMIN']);
        $client->loginUser($master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    private function clienteDeEscritorio(bool $primario): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarFontesFalsas();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = (new Tenant())->setName('Escritório Painel ' . uniqid());
        $em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName($primario ? 'Administrador do Escritório' : 'Colaborador')->setIsSystem($primario);
        $em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        if ($primario) {
            $tenant->setCriadoPor($user);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail('painel_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário Painel');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function instalarFontesFalsas(): void
    {
        $http = new MockHttpClient(function (string $metodo, string $url): MockResponse {
            if ($this->redeFora) {
                return new MockResponse('', ['http_code' => 503]);
            }
            if (str_contains($url, '/malhas/')) {
                return new MockResponse('{"type":"FeatureCollection","features":[{"type":"Feature","properties":{"codarea":"2704302"},"geometry":null}]}');
            }
            if (str_contains($url, '/localidades/')) {
                return new MockResponse('[{"id":2704302,"nome":"Maceió"}]');
            }
            if (str_contains($url, '/agregados/') && str_contains(urldecode($url), 'N6')) {
                return new MockResponse('[{"resultados":[{"series":[{"localidade":{"id":"2704302","nome":"Maceió - AL"},"serie":{"2025":"957916"}}]}]}]');
            }
            if (str_contains($url, '/agregados/')) {
                $series = [];
                foreach (ClienteIbge::CODIGO_UF as $sigla => $codigo) {
                    $series[] = ['localidade' => ['id' => (string) $codigo, 'nome' => $sigla], 'serie' => ['2025' => '1000000']];
                }

                return new MockResponse((string) json_encode([['resultados' => [['series' => $series]]]]));
            }

            return new MockResponse('', ['http_code' => 404]);
        });
        $relogio = new MockClock('2026-10-16 10:00:00');

        // Armazém de CSRF fixo (padrão dos testes do projeto): o token 'TOKEN_<id>' é sempre válido,
        // então os 403 de primário/secundário vêm do IsGranted, não do CSRF.
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        });

        static::getContainer()->set(FontesPublicas::class, new FontesPublicas(
            new ArmazemDeFontes(new ArrayAdapter(0, false), $relogio, sys_get_temp_dir() . '/master-fontes-teste-' . uniqid()),
            new ClienteIbge($http),
            new ClienteQuadroOab($http),
            new LeitorQuadroOab(),
            $relogio,
            new NullLogger(),
        ));
    }

    private function csv(string $conteudo): UploadedFile
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'quadro.csv', 'text/csv', null, true);
    }
}
