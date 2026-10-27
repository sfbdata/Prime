<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Controller\MarcaDoAlertaDaInteligenciaController;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Painel BlueJus Intelligence: selo/gauge/ritmo necessário da meta de pastas (em dias úteis) e os
 * botões por alerta da Central (Resolvido, Ignorar, Aplicar), com efeito real e por usuário.
 * Desenho: "01 - Dashboard - VS 25.10.26", L966-975 e L1198-1218.
 */
#[CoversClass(MarcaDoAlertaDaInteligenciaController::class)]
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardInteligenciaAlertasTelaTest extends DashboardWebTestCase
{
    use Factories;

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

    /** @param array<string, mixed>|null $corpo */
    private function marcar(KernelBrowser $client, ?array $corpo, string $token = 'TOKEN_ajax'): void
    {
        $client->request(
            'POST',
            '/dashboard/inteligencia/alerta',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode($corpo ?? new \stdClass(), JSON_THROW_ON_ERROR),
        );
    }

    /** Uma meta vencida de um colaborador: faz a Central emitir o alerta "vencidas". */
    private function criarMetaVencida(Tenant $tenant): void
    {
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00')])->_real();
        $bruno  = $this->criarColaborador($tenant, 'Bruno Melo');
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('-5 days')])->_real();
        $tarefa->addResponsavel($bruno);
        $this->em()->flush();
    }

    private function alerta(Crawler $crawler, string $id): Crawler
    {
        return $crawler->filter('.db-ia-alertas > details.db-ia-alerta[data-alerta="' . $id . '"]');
    }

    /** @return list<array<string, mixed>> */
    private function marcasNoBanco(Tenant $tenant, User $usuario): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            "SELECT valor FROM preferencia_usuario WHERE tenant_id = :t AND user_id = :u AND chave = 'dashboard.ia_alertas'",
            ['t' => $tenant->getId(), 'u' => $usuario->getId()],
        );

        return array_map(static fn (array $l): array => json_decode((string) $l['valor'], true, 512, JSON_THROW_ON_ERROR), $linhas);
    }

    private function definirMeta(Tenant $tenant, User $autor, int $valor): void
    {
        $this->em()->persist(new DashboardMetaPastas($tenant, new \DateTimeImmutable('first day of this month'), $valor, $autor));
        $this->em()->flush();
    }

    private function mesInteiro(): string
    {
        $mes = new \DateTimeImmutable('today');

        return 'data_de=' . $mes->format('Y-m-01') . '&data_ate=' . $mes->format('Y-m-t');
    }

    #[TestDox('Com meta e o mês inteiro: selo do estado, gauge de 4 faixas com marcador, Ritmo atual e Ritmo necessário')]
    public function testSeloGaugeERitmoNecessario(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->definirMeta($tenant, $user, 9999);

        $crawler = $client->request('GET', '/dashboard?' . $this->mesInteiro());

        self::assertResponseIsSuccessful();
        $bloco = $crawler->filter('aside.db-ia-painel > .db-ia-corpo > section.db-ia-meta-ritmo');
        self::assertCount(1, $bloco, 'bloco do ritmo da meta, filho direto do corpo do painel');
        self::assertCount(1, $bloco->filter('.db-ia-selo-linha > .db-ia-selo'));
        self::assertMatchesRegularExpression('/^Ritmo (adequado|de atenção|abaixo do necessário|crítico)$/u', trim($bloco->filter('.db-ia-selo')->text()));
        self::assertCount(4, $bloco->filter('.db-ia-gauge > .db-ia-gauge-faixa'));
        self::assertCount(1, $bloco->filter('.db-ia-gauge > .db-ia-gauge-marcador'));
        self::assertMatchesRegularExpression('/^dia útil \d+ de \d+$/u', trim($bloco->filter('.db-ia-dia')->text()));
        $rotulos = $bloco->filter('.db-ia-ritmo-grade > .db-ia-mini > .db-ia-mini-rotulo')->each(static fn (Crawler $n): string => trim($n->text()));
        self::assertSame(['Ritmo atual', 'Ritmo necessário'], $rotulos);
        self::assertStringContainsString('de 9999 criadas', $bloco->filter('.db-ia-barra-legenda')->text(), 'meta até 9999');
    }

    #[TestDox('Sem meta, ou com recorte que não é o mês inteiro: o bloco não existe (nada de objetivo inventado)')]
    public function testSemMetaOuRecorteNaoHaSelo(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $semMeta = $client->request('GET', '/dashboard?' . $this->mesInteiro());
        self::assertCount(0, $semMeta->filter('.db-ia-meta-ritmo'));

        $this->definirMeta($tenant, $user, 100);
        $outroRecorte = $client->request('GET', '/dashboard?data_de=2024-02-01&data_ate=2024-02-29');
        self::assertCount(0, $outroRecorte->filter('.db-ia-meta-ritmo'));
    }

    #[TestDox('A meta é do escritório: a de outro escritório não aparece no selo')]
    public function testMetaDeOutroEscritorioNaoVaza(): void
    {
        $client = static::createClient();
        [, $tenantA] = $this->criarGestorLogado($client);
        $tenantB     = $this->criarTenant();
        $gestorB     = $this->criarUsuarioComPermissaoBi($tenantB);
        $this->definirMeta($tenantB, $gestorB, 500);

        $crawler = $client->request('GET', '/dashboard?' . $this->mesInteiro());

        self::assertNotNull($tenantA->getId());
        self::assertCount(0, $crawler->filter('.db-ia-meta-ritmo'));
    }

    #[TestDox('O alerta traz Resolvido, Ignorar e "A recomendação se aplica? Aplicar / Não se aplica"')]
    public function testAlertaTemOsBotoes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarMetaVencida($tenant);

        $alerta = $this->alerta($client->request('GET', '/dashboard'), 'vencidas');

        self::assertCount(1, $alerta);
        $botoes = $alerta->filter('.db-ia-alerta-corpo button[data-ia-marcar]')->each(static fn (Crawler $b): string => $b->attr('data-ia-marcar') . ':' . trim($b->text()));
        self::assertSame(['resolvido:Resolvido', 'ignorado:Ignorar', 'sim:Aplicar', 'nao:Não se aplica'], $botoes);
        self::assertStringNotContainsString('Criar tarefa', $alerta->text(), 'sem destino no sistema, o botão não é prometido');
        self::assertMatchesRegularExpression('#/dashboard/inteligencia/alerta$#', (string) $client->getCrawler()->filter('[data-ia-alertas]')->attr('data-url'));
    }

    #[TestDox('Resolvido grava a marca do usuário: o alerta volta esmaecido, com o selo "Resolvido", e repetir desfaz')]
    public function testResolvidoGravaEPersiste(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // o token de teste vive no container, que precisa durar as várias requisições
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->criarMetaVencida($tenant);

        $this->marcar($client, ['alerta' => 'vencidas', 'acao' => 'resolvido']);

        self::assertResponseIsSuccessful();
        self::assertSame(['alerta' => 'vencidas', 'status' => 'resolvido', 'feedback' => null], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame([['vencidas' => ['status' => 'resolvido', 'feedback' => null]]], $this->marcasNoBanco($tenant, $user));

        $alerta = $this->alerta($client->request('GET', '/dashboard'), 'vencidas');
        self::assertStringContainsString('is-marcado', (string) $alerta->attr('class'));
        self::assertSame('Resolvido', trim($alerta->filter('[data-ia-status]')->text()));
        self::assertSame('true', $alerta->filter('[data-ia-marcar="resolvido"]')->attr('aria-pressed'));

        $this->marcar($client, ['alerta' => 'vencidas', 'acao' => 'resolvido']);
        self::assertSame([[]], $this->marcasNoBanco($tenant, $user), 'repetir desfaz');
    }

    #[TestDox('A marca é pessoal: outro usuário do mesmo escritório vê o alerta sem marca')]
    public function testMarcaEPessoal(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // o token de teste vive no container, que precisa durar as várias requisições
        $this->instalarCsrfStorage();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarMetaVencida($tenant);
        $this->marcar($client, ['alerta' => 'vencidas', 'acao' => 'ignorado']);
        self::assertResponseIsSuccessful();

        $outro = $this->criarUsuarioComPermissaoBi($tenant);
        $this->logarComTenant($client, $outro, $tenant);
        $alerta = $this->alerta($client->request('GET', '/dashboard'), 'vencidas');

        self::assertStringNotContainsString('is-marcado', (string) $alerta->attr('class'));
        self::assertSame('false', $alerta->filter('[data-ia-marcar="ignorado"]')->attr('aria-pressed'));
    }

    #[TestDox('Recusas: CSRF errado é 403; corpo ou alerta inventado é 400; sem módulo bi é 403; nada é gravado')]
    public function testRecusas(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // o token de teste vive no container, que precisa durar as várias requisições
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $semBi = $this->criarColaborador($tenant, 'Sem Painel');

        $this->marcar($client, ['alerta' => 'vencidas', 'acao' => 'resolvido'], 'errado');
        self::assertResponseStatusCodeSame(403);

        $this->marcar($client, ['alerta' => 'inventado', 'acao' => 'resolvido']);
        self::assertResponseStatusCodeSame(400);

        $this->marcar($client, ['alerta' => 'vencidas', 'acao' => 'tarefa']);
        self::assertResponseStatusCodeSame(400);

        $this->marcar($client, null);
        self::assertResponseStatusCodeSame(400);

        self::assertSame([], $this->marcasNoBanco($tenant, $user));

        $this->logarComTenant($client, $semBi, $tenant);
        $this->marcar($client, ['alerta' => 'vencidas', 'acao' => 'resolvido']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Perguntas guiadas: pílulas filhas diretas do contêiner e uma só caixa de resposta')]
    public function testPerguntasPilulasECaixaUnica(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarMetaVencida($tenant);

        $crawler = $client->request('GET', '/dashboard');

        $pilulas = $crawler->filter('.db-ia-perguntas > .db-ia-pilulas > button.db-ia-pilula[aria-pressed="false"][data-ia-pergunta]');
        self::assertGreaterThan(1, $pilulas->count());
        self::assertCount(1, $crawler->filter('.db-ia-perguntas > [data-ia-resposta-caixa][hidden]'));
        self::assertCount(0, $crawler->filter('.db-ia-perguntas details'));
    }
}
