<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPushClienteController;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DefinirEnvioAutomaticoDoClienteUseCase;
use App\Pasta\UseCase\MontarAvisarClienteUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O bloco "Envio automático" do cartão Avisar cliente (spec push-envio-automatico §2 e §4): os textos do
 * desenho, as três chaves desligadas (D1), "Análise da BlueJus IA · Só manual"; sem e-mail, esmaecidas
 * e com o prefixo da dica; a rota das chaves (CSRF, EDITAR, outro escritório, sem e-mail, estado
 * desejado), que grava POR CLIENTE.
 */
#[CoversClass(PastaPushClienteController::class)]
#[CoversClass(DefinirEnvioAutomaticoDoClienteUseCase::class)]
#[CoversClass(MontarAvisarClienteUseCase::class)]
#[Group('pasta')]
final class PastaPushEnvioAutomaticoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const CARTAO = '#push > .ps-push-grade > aside.ps-push-coluna > .ps-push-cliente';

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

    /** @return array{0: KernelBrowser, 1: User, 2: Tenant, 3: Pasta, 4: ClientePF} */
    private function cenario(string $emailDaFicha = 'maria.ficha@cliente.com.br'): array
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $cliente = $this->criarClientePF($tenant, $pasta, 'Maria da Silva Souza');
        $cliente->setEmail($emailDaFicha);
        $this->em()->flush();
        $this->instalarCsrfStorage();

        return [$client, $user, $tenant, $pasta, $cliente];
    }

    private function postar(KernelBrowser $client, Pasta $pasta, string $chave, string $ligado, ?string $token = null): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/push/cliente/automatico', [
            'chave' => $chave,
            'ligado' => $ligado,
            '_token' => $token ?? 'TOKEN_pasta_push_cliente_' . $pasta->getId(),
        ]);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function cartao(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter(self::CARTAO);
    }

    private function linha(ClientePF $cliente): ?EnvioAutomaticoDoCliente
    {
        $this->em()->clear();

        return $this->em()->getRepository(EnvioAutomaticoDoCliente::class)->findOneBy(['cliente' => $cliente->getId()]);
    }

    #[TestDox('Tela: "Envio automático" com as três chaves do desenho, desligadas (D1), e a linha fixa "Análise da BlueJus IA · Só manual"')]
    public function testBlocoNaTela(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $cartao = $this->cartao($client, $pasta);

        $classes = $cartao->children()->each(static fn (Crawler $no): string => explode(' ', (string) $no->attr('class'))[0]);
        $depoisDoDivisor = \array_slice($classes, (int) array_search('ps-push-cliente-divisor', $classes, true) + 1, 5);
        self::assertSame(['ps-push-cliente-auto-titulo', 'ps-push-cliente-auto', 'ps-push-cliente-auto', 'ps-push-cliente-auto', 'ps-push-cliente-auto-ia'], $depoisDoDivisor);
        self::assertSame('Envio automático', $cartao->filter('.ps-push-cliente > .ps-push-cliente-auto-titulo')->text());

        $chaves = $cartao->filter('.ps-push-cliente > button.ps-push-cliente-auto[role="switch"]');
        self::assertSame(['Novas movimentações', 'Audiências e intimações', 'Resumo semanal'], $chaves->each(static fn (Crawler $b): string => $b->filter('.ps-push-cliente-auto-rotulo')->text()));
        self::assertSame(['false', 'false', 'false'], $chaves->each(static fn (Crawler $b): string => (string) $b->attr('aria-checked')));
        self::assertSame([
            'Envia ao cliente um aviso com o texto oficial da movimentação, assim que ela chega',
            'Avisa quando chegar intimação ou designação de audiência',
            'Toda segunda-feira, um resumo das movimentações da semana anterior',
        ], $chaves->each(static fn (Crawler $b): string => (string) $b->attr('title')));
        self::assertSame(['novas_movimentacoes', 'audiencias_intimacoes', 'resumo_semanal'], $chaves->each(static fn (Crawler $b): string => (string) $b->attr('data-push-cliente-auto')));
        self::assertSame(0, $chaves->filter('.is-sem-email')->count());
        self::assertSame(0, $chaves->filter('[disabled]')->count());

        $ia = $cartao->filter('.ps-push-cliente > .ps-push-cliente-auto-ia');
        self::assertSame('Análise da BlueJus IA', $ia->filter('.ps-push-cliente-auto-rotulo')->text());
        self::assertSame('Só manual', trim($ia->filter('.ps-push-cliente-so-manual')->text()));
        self::assertCount(1, $ia->filter('.ps-push-cliente-so-manual > i.bi-lock-fill'));
        self::assertSame('A análise depende de correção e revisão do advogado antes de chegar ao cliente', $ia->attr('title'));
        self::assertCount(0, $ia->filter('[role="switch"]'), 'sem switch (D5)');
        self::assertStringNotContainsString('—', $cartao->html());
    }

    #[TestDox('Sem e-mail nenhum: chaves esmaecidas com "Cadastre um e-mail para ativar. " na dica; ligar dá 422; desligar o que está desligado é aceito')]
    public function testSemEmail(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario('sem-email');
        $this->logarComTenant($client, $user, $tenant);

        $chaves = $this->cartao($client, $pasta)->filter('.ps-push-cliente > button.ps-push-cliente-auto');
        self::assertSame(3, $chaves->filter('.is-sem-email')->count());
        self::assertSame('Cadastre um e-mail para ativar. Envia ao cliente um aviso com o texto oficial da movimentação, assim que ela chega', $chaves->first()->attr('title'));
        self::assertNull($chaves->first()->attr('disabled'), 'o clique abre o formulário de cadastro');

        $this->postar($client, $pasta, 'novas_movimentacoes', '1');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Cadastre um e-mail para ativar.'], $this->json($client));
        self::assertNull($this->linha($cliente));

        $this->postar($client, $pasta, 'resumo_semanal', '0');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Liga e desliga pelo estado desejado: grava POR CLIENTE (quem e quando), devolve o cartão com a chave ligada, e outra pasta do mesmo cliente mostra igual')]
    public function testLigaEDesliga(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, $pasta, 'audiencias_intimacoes', '1');
        self::assertResponseIsSuccessful();
        $html = new Crawler((string) $this->json($client)['html']);
        self::assertSame('true', $html->filter('[data-push-cliente-auto="audiencias_intimacoes"]')->attr('aria-checked'));
        self::assertSame('false', $html->filter('[data-push-cliente-auto="novas_movimentacoes"]')->attr('aria-checked'));
        $linha = $this->linha($cliente);
        self::assertNotNull($linha);
        self::assertTrue($linha->ligada(ChaveDoEnvioAutomatico::AudienciasIntimacoes));
        self::assertFalse($linha->ligada(ChaveDoEnvioAutomatico::NovasMovimentacoes));
        self::assertSame($user->getId(), $linha->getAtualizadoPor()?->getId());

        // Repetir o pedido não desfaz (estado desejado).
        $this->postar($client, $pasta, 'audiencias_intimacoes', '1');
        self::assertTrue($this->linha($cliente)?->ligada(ChaveDoEnvioAutomatico::AudienciasIntimacoes));

        // A chave é do cliente: outra pasta dele mostra ligada.
        $tenant = $this->em()->find(Tenant::class, $tenant->getId()) ?? self::fail();
        $outraPasta = $this->criarPasta($tenant);
        $outraPasta->addCliente($this->em()->find(ClientePF::class, $cliente->getId()) ?? self::fail());
        $this->em()->flush();
        self::assertSame('true', $this->cartao($client, $outraPasta)->filter('[data-push-cliente-auto="audiencias_intimacoes"]')->attr('aria-checked'));

        $this->postar($client, $pasta, 'audiencias_intimacoes', '0');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->linha($cliente)?->ligada(ChaveDoEnvioAutomatico::AudienciasIntimacoes));
    }

    #[TestDox('Guarda: sem CSRF 403; chave desconhecida 422; só VER 403 (e a tela trava as chaves com a dica); outro escritório 404; nada é gravado')]
    public function testGuardas(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $pasta, 'novas_movimentacoes', '1', 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, $pasta, 'analise_ia', '1');
        self::assertResponseStatusCodeSame(422);

        $tenant = $this->em()->find(Tenant::class, $tenant->getId()) ?? self::fail();
        $soVe = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $soVe, $tenant);
        $this->postar($client, $pasta, 'novas_movimentacoes', '1');
        self::assertResponseStatusCodeSame(403);
        $chaves = $this->cartao($client, $pasta)->filter('.ps-push-cliente > button.ps-push-cliente-auto');
        self::assertSame(3, $chaves->filter('[disabled]')->count());
        self::assertSame('Só quem edita a pasta muda os e-mails do cliente.', $chaves->first()->attr('title'));

        [$intruso, $outroTenant] = $this->criarAdmin();
        $this->logarComTenant($client, $intruso, $outroTenant);
        $this->postar($client, $pasta, 'novas_movimentacoes', '1');
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->linha($cliente));
    }
}
