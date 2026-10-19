<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Pasta\Controller\PastaPushPainelController;
use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DefinirAvisoDoPushUseCase;
use App\Pasta\UseCase\MontarAvisosDoPushUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O cartão "Avisar quando chegar" (desenho 1.2.3, dc L.2629-2636; spec push-avisar-quando-chegar §2):
 * a tela (filho direto da coluna, textos e estados) e a rota das chaves (`pasta_push_avisos`: CSRF,
 * EDITAR, VER 403, outro escritório 404, chave travada 422, lápide).
 */
#[CoversClass(PastaPushPainelController::class)]
#[CoversClass(DefinirAvisoDoPushUseCase::class)]
#[CoversClass(MontarAvisosDoPushUseCase::class)]
#[Group('pasta')]
final class PastaPushAvisosTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const CARTAO = '#push > .ps-push-grade > aside.ps-push-coluna > .ps-push-avisos';

    private function instalarCsrfStorage(): void
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

    /** @return array{0: KernelBrowser, 1: User, 2: Tenant, 3: Pasta} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();

        return [$client, $user, $tenant, $pasta];
    }

    /** @param string $ligado o estado DESEJADO ('1' ou '0') */
    private function postar(KernelBrowser $client, Pasta $pasta, string $chave, string $ligado, ?string $token = null): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/push/avisos', [
            '_token' => $token ?? 'TOKEN_pasta_push_avisos_' . $pasta->getId(),
            'chave' => $chave,
            'ligado' => $ligado,
        ]);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function linha(Pasta $pasta): ?AvisoDoPushDaPasta
    {
        $this->em()->clear();

        return $this->em()->getRepository(AvisoDoPushDaPasta::class)->findOneBy(['pasta' => $pasta->getId()]);
    }

    private function cartao(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter(self::CARTAO);
    }

    #[TestDox('Tela: o cartão é filho direto da coluna, depois do monitoramento, com "Avisar quando chegar" e as três chaves do desenho (padrões: responsável e e-mail ligados, IA desligada)')]
    public function testCartaoNaTela(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $cartao = $this->cartao($client, $pasta);

        self::assertCount(1, $cartao);
        $coluna = $cartao->ancestors()->first()->children()->each(static fn (Crawler $no): string => (string) $no->attr('class'));
        self::assertSame(['ps-push-painel', 'ps-push-avisos'], $coluna, 'monitoramento e depois os avisos');
        self::assertSame('Avisar quando chegar', $cartao->filter('.ps-push-avisos > .ps-push-avisos-titulo')->text());
        $chaves = $cartao->filter('.ps-push-avisos > button.ps-push-aviso[role="switch"]');
        self::assertSame(['Responsável da pasta', 'Por e-mail', 'Análise da BlueJus IA'], $chaves->each(static fn (Crawler $b): string => $b->filter('.ps-push-aviso-rotulo')->text()));
        self::assertSame(['true', 'true', 'false'], $chaves->each(static fn (Crawler $b): string => (string) $b->attr('aria-checked')));
        self::assertSame(['responsavel', 'email', 'ia'], $chaves->each(static fn (Crawler $b): string => (string) $b->attr('data-push-aviso')));
        self::assertNull($chaves->eq(0)->attr('disabled'));
        self::assertNull($chaves->eq(1)->attr('disabled'));
        // A IA não está ligada neste escritório: travada com o motivo real.
        self::assertNotNull($chaves->eq(2)->attr('disabled'));
        self::assertSame('BlueJus IA desligada neste escritório. Peça ao administrador.', $chaves->eq(2)->attr('title'));
        self::assertCount(3, $cartao->filter('.ps-push-aviso > .ps-push-aviso-sw > .ps-push-switch-bola'), 'um switch por linha');
        self::assertStringNotContainsString('—', $cartao->html());
    }

    #[TestDox('Rota (estado desejado): desliga o responsável (o e-mail trava com a dica), repetir não inverte, religa; a linha guarda quem mexeu')]
    public function testDefinir(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, $pasta, 'responsavel', '0');

        self::assertResponseIsSuccessful();
        $avisos = $this->json($client)['avisos'];
        self::assertSame(['chave' => 'responsavel', 'ligado' => false, 'travado' => false, 'dica' => null], $avisos[0]);
        self::assertSame(['chave' => 'email', 'ligado' => true, 'travado' => true, 'dica' => 'Ligue o aviso ao responsável para receber por e-mail.'], $avisos[1]);
        $linha = $this->linha($pasta);
        self::assertNotNull($linha);
        self::assertSame($user->getId(), $linha->getAtualizadoPor()?->getId());

        $this->postar($client, $pasta, 'responsavel', '0');
        self::assertFalse($this->json($client)['avisos'][0]['ligado'], 'o segundo clique (ou a outra aba) não inverte');

        $this->postar($client, $pasta, 'email', '0');
        self::assertResponseIsSuccessful();
        $this->postar($client, $pasta, 'email', '1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Ligue o aviso ao responsável para receber por e-mail.'], $this->json($client));

        $cartao = $this->cartao($client, $pasta);
        self::assertSame('false', $cartao->filter('[data-push-aviso="responsavel"]')->attr('aria-checked'));
        self::assertNotNull($cartao->filter('[data-push-aviso="email"]')->attr('disabled'));
        self::assertSame('Ligue o aviso ao responsável para receber por e-mail.', $cartao->filter('[data-push-aviso="email"]')->attr('title'));

        $this->postar($client, $pasta, 'responsavel', '1');
        self::assertTrue($this->json($client)['avisos'][0]['ligado']);
        $this->postar($client, $pasta, 'email', '1');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['avisos'][1]['ligado']);
        self::assertCount(1, $this->em()->getRepository(AvisoDoPushDaPasta::class)->findBy(['pasta' => $pasta->getId()]), 'uma linha só');
    }

    #[TestDox('Rota: sem CSRF 403; chave ou valor desconhecido 422; IA desligada no escritório 422 com o motivo; nada gravado')]
    public function testRecusas(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, $pasta, 'responsavel', '0', 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, $pasta, 'sms', '1');
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, $pasta, 'responsavel', 'talvez');
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, $pasta, 'ia', '1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'BlueJus IA desligada neste escritório. Peça ao administrador.'], $this->json($client));
        self::assertNull($this->linha($pasta));
    }

    #[TestDox('IA ligada no escritório e responsável da pasta sem modules.inteligencia.view: a chave da IA trava com a dica e ligar dá 422')]
    public function testResponsavelSemIa(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->atualizar(true, 50, 500, true, $user);
        $this->em()->persist($configuracao);
        $pasta->setResponsavel($this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']));
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $ia = $this->cartao($client, $pasta)->filter('[data-push-aviso="ia"]');
        self::assertNotNull($ia->attr('disabled'));
        self::assertSame('O responsável da pasta não tem acesso à BlueJus IA.', $ia->attr('title'));

        $this->postar($client, $pasta, 'ia', '1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O responsável da pasta não tem acesso à BlueJus IA.'], $this->json($client));
    }

    #[TestDox('Só VER a pasta: a rota responde 403 e a tela mostra as chaves desabilitadas com a dica')]
    public function testSoVer(): void
    {
        [$client, , $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']), $tenant);

        $this->postar($client, $pasta, 'responsavel', '0');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->linha($pasta));

        $chaves = $this->cartao($client, $pasta)->filter('button.ps-push-aviso');
        self::assertCount(3, $chaves);
        foreach ($chaves as $chave) {
            self::assertTrue($chave->hasAttribute('disabled'));
            self::assertSame('Só quem edita a pasta muda os avisos.', $chave->getAttribute('title'));
        }
    }

    #[TestDox('Pasta de outro escritório: 404 e nada gravado')]
    public function testOutroEscritorio(): void
    {
        [$client, , , $pasta] = $this->cenario();
        [$intruso, $outroTenant] = $this->criarAdmin();
        $this->logarComTenant($client, $intruso, $outroTenant);

        $this->postar($client, $pasta, 'responsavel', '0');

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->linha($pasta));
    }

    #[TestDox('Pasta excluída (lápide): a rota recusa e a tela trava as chaves com "Pasta excluída: somente leitura."')]
    public function testLapide(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        // O JS chama com X-Requested-With: o listener da lápide responde JSON de erro (sem ele, redireciona).
        $client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/push/avisos', ['_token' => 'TOKEN_pasta_push_avisos_' . $pasta->getId(), 'chave' => 'responsavel', 'ligado' => '0']);
        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
        self::assertNull($this->linha($pasta));

        $chave = $this->cartao($client, $pasta)->filter('[data-push-aviso="responsavel"]');
        self::assertNotNull($chave->attr('disabled'));
        self::assertSame('Pasta excluída: somente leitura.', $chave->attr('title'));
    }
}
