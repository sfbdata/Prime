<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Conector\Entity\EscritorioDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Controller\ConectorDoMasterController;
use App\Master\Controller\IaDoMasterController;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O conector de I.A. na aba "Configurações da I.A." do Master (spec mcp-remoto-oauth 14.a; PR 8): o JSON da
 * tabela, só leitura. Só o Master. O Master não libera escritório por escritório (decisão do Samuel, 19/11): a rota de
 * liberar sumiu.
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, como no IaDoMasterControllerTest): o 403 de quem não é Master
 * vem da regra, não do token. O CSRF errado tem teste próprio.
 */
#[CoversClass(ConectorDoMasterController::class)]
final class ConectorDoMasterControllerTest extends JusPrimeWebTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    private const TOKEN = 'TOKEN_' . IaDoMasterController::TOKEN;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFixo();
    }

    #[TestDox('Master lê a tabela: escritório sem linha desligado, o ligado com aceite e a contagem; suspenso fora')]
    public function testMasterLe(): void
    {
        $this->entrarComoMaster();
        $semLinha = $this->escritorio();
        $ligado = $this->escritorio();
        $this->liberar($ligado, $this->pessoa());
        $suspenso = $this->escritorio();
        $suspenso->setIsActive(false);
        $this->em()->flush();

        $this->client->request('GET', '/master/conector', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(['tenantId' => $semLinha->getId(), 'nome' => $semLinha->getName(), 'ligado' => false, 'aceiteEm' => null, 'aceiteVigente' => false, 'conexoesAtivas' => 0], $this->linhaDe($d, $semLinha));
        $linha = $this->linhaDe($d, $ligado);
        self::assertTrue($linha['ligado']);
        self::assertTrue($linha['aceiteVigente']);
        self::assertSame($this->agora()->format('Y-m-d'), $linha['aceiteEm']);
        self::assertNull($this->linhaDe($d, $suspenso, false));
    }

    #[TestDox('quem não é Master (primário de escritório) recebe 403 no GET')]
    public function testNaoMaster403(): void
    {
        $alvo = $this->escritorio();
        $this->entrarComoPrimarioDeEscritorio();

        $this->client->request('GET', '/master/conector', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->linhaNoBanco($alvo));
    }

    #[TestDox('sem login: não chega ao JSON')]
    public function testSemLogin(): void
    {
        $this->client->request('GET', '/master/conector');

        self::assertTrue($this->client->getResponse()->isRedirect(), 'vai para o login');
    }

    #[TestDox('a rota de liberar escritório não existe mais: o POST do Master dá 404 e nenhuma linha nasce')]
    public function testLiberarPorEscritorioNaoExiste(): void
    {
        $this->entrarComoMaster();
        $alvo = $this->escritorio();

        $this->postar('/master/conector/escritorios/' . $alvo->getId(), ['liberado' => true]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->linhaNoBanco($alvo));
    }

    #[TestDox('tela: o conector entra na aba "Configurações da I.A." depois do bloco da BlueJus IA, com a rota de dados, as colunas, o catálogo e o JS')]
    public function testTela(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $raiz = $crawler->filter('#master-painel > div.mp-ia[data-painel-aba="ia"] > div.mp-ia-raiz[data-mp-ia] + div.mp-ia-raiz[data-mp-conector]');
        self::assertCount(1, $raiz);
        self::assertSame('/master/conector', $raiz->attr('data-url-dados'));
        self::assertNull($raiz->attr('data-url-escritorio'), 'sem a rota de liberar');
        $card = $raiz->filter('div.mp-ia-raiz[data-mp-conector] > section.mp-card');
        self::assertCount(1, $card);
        self::assertSame('Conector de I.A.', trim($card->filter('section > div.mp-cab-roxo > b')->text()));
        self::assertSame(['Escritório', 'Ligado pelo escritório', 'Aceite do escritório', 'Conexões ativas'], $card->filter('div.mp-rolagem > div.mp-ia-grade > div.mp-ia-cab > div')->each(static fn ($c): string => trim($c->text())));
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $raiz->text());
        self::assertCount(1, $crawler->filter('script[src*="js/master/conector.js"]'));
        $catalogo = $crawler->filter('script[type="application/json"][data-bj-t]')->reduce(static fn ($c): bool => str_contains($c->text(), 'conector.js.master.vazio'));
        self::assertCount(1, $catalogo, 'o catálogo conector.js vai junto');
    }

    #[TestDox('JS do conector: sem texto cru (tudo pelo catálogo conector.js.*) e as chaves usadas existem, sem travessão')]
    public function testJsUsaOCatalogo(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/conector.js');
        preg_match_all("/'(conector\\.js\\.[a-z_.]+)'/", $js, $m);
        $tradutor = static::getContainer()->get('translator');

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $chave) {
            self::assertNotSame($chave, $tradutor->trans($chave, [], null, 'pt_BR'), $chave . ' existe no catálogo');
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $tradutor->trans($chave, [], null, 'pt_BR'));
        }
    }

    private function entrarComoMaster(): User
    {
        $master = $this->pessoa(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = $this->escritorio();
        $user = $this->pessoa();
        $this->vinculo($user, $tenant, sistema: true);
        $tenant->setCriadoPor($user);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** A linha como está no banco, sem o `TenantFilter` que uma requisição tenha deixado ligado. */
    private function linhaNoBanco(Tenant $tenant): ?EscritorioDoConector
    {
        $this->em()->clear();
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }

        return $this->em()->getRepository(EscritorioDoConector::class)->findOneBy(['tenant' => $tenant->getId()]);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return ?array<string, mixed>
     */
    private function linhaDe(array $dados, Tenant $tenant, bool $exigir = true): ?array
    {
        foreach ($dados['escritorios'] as $linha) {
            if ($linha['tenantId'] === $tenant->getId()) {
                return $linha;
            }
        }
        if ($exigir) {
            self::fail('o escritório ' . $tenant->getId() . ' não está na tabela');
        }

        return null;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, server: [
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

    private function instalarCsrfFixo(): void
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
