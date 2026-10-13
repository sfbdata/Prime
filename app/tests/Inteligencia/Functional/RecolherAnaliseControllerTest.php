<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Controller\AnalisePushController;
use App\Inteligencia\Preferencia\CatalogoDePreferenciasDaInteligencia;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * IA-010: recolher e expandir a análise do Push, com prévia. A escolha é do USUÁRIO e fica no
 * servidor (`preferencia_usuario`, chave `push.ia.recolhidas`), nunca no navegador. Análise de
 * outro escritório ou de pasta irmã → 404 e nada gravado.
 */
#[CoversClass(AnalisePushController::class)]
final class RecolherAnaliseControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private bool $csrfInstalado = false;

    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    private function recolher(KernelBrowser $client, int $pastaId, int $analiseId, string $recolhida, bool $comToken = true): void
    {
        $client->request(
            'POST',
            "/pasta/{$pastaId}/ia/analises/{$analiseId}/recolher",
            ['recolhida' => $recolhida] + ($comToken ? ['_token' => 'TOKEN_inteligencia_analise_' . $analiseId] : []),
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    /** @return list<int>|null o valor gravado, ou null sem linha */
    private function gravado(Tenant $tenant, User $user): ?array
    {
        $valor = $this->em()->getConnection()->fetchOne(
            'SELECT valor FROM preferencia_usuario WHERE tenant_id = :t AND user_id = :u AND chave = :c',
            ['t' => $tenant->getId(), 'u' => $user->getId(), 'c' => CatalogoDePreferenciasDaInteligencia::PUSH_RECOLHIDAS],
        );

        return $valor === false ? null : json_decode((string) $valor, true, 512, JSON_THROW_ON_ERROR);
    }

    private function linhasDePreferencia(): int
    {
        return $this->contarNoBanco(
            'SELECT count(*) FROM preferencia_usuario WHERE chave = :c',
            ['c' => CatalogoDePreferenciasDaInteligencia::PUSH_RECOLHIDAS],
        );
    }

    private function cartao(Crawler $crawler, int $analiseId): Crawler
    {
        return $crawler->filter('.ps-ia-lista > .ps-ia-cartao[data-ia-analise="' . $analiseId . '"]');
    }

    #[TestDox('recolher grava o id no servidor; a lista volta com a prévia "resumo · N ponto(s)" e o chevron fechado; expandir desfaz')]
    public function testRecolherEExpandir(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta, ['pub:1']);
        $this->concluirAnalise($analise, 'Sentença publicada.', [['tipo' => 'prazo', 'texto' => 'Apelação.'], ['tipo' => 'info', 'texto' => 'Custas.']]);
        $id = (int) $analise->getId();
        $this->logarComTenant($client, $user, $tenant);

        // Aberta por padrão: chevron filho direto do cabeçalho, expandido; prévia escondida.
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");
        $cartao = $this->cartao($crawler, $id);
        $chevron = $cartao->filter('.ps-ia-corpo > .ps-ia-cab > button[aria-expanded][data-ia-recolher]');
        self::assertSame(1, $chevron->count());
        self::assertSame('true', $chevron->attr('aria-expanded'));
        self::assertSame('Recolher a análise', $chevron->attr('title'));
        self::assertSame("ps-ia-recolher-{$id}", $chevron->attr('form'));
        self::assertNotNull($cartao->filter('.ps-ia-corpo > .ps-ia-previa')->attr('hidden'));
        self::assertStringNotContainsString('ps-ia-cartao--recolhida', (string) $cartao->attr('class'));
        self::assertSame('1', $cartao->filter("form#ps-ia-recolher-{$id} input[name=recolhida]")->attr('value'));

        $this->recolher($client, (int) $pasta->getId(), $id, '1');
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['recolhida']);
        self::assertSame([$id], $this->gravado($tenant, $user));

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");
        $cartao = $this->cartao($crawler, $id);
        self::assertStringContainsString('ps-ia-cartao--recolhida', (string) $cartao->attr('class'));
        $chevron = $cartao->filter('.ps-ia-corpo > .ps-ia-cab > button[data-ia-recolher]');
        self::assertSame('false', $chevron->attr('aria-expanded'));
        self::assertSame('Expandir a análise', $chevron->attr('title'));
        $previa = $cartao->filter('.ps-ia-corpo > button.ps-ia-previa');
        self::assertNull($previa->attr('hidden'));
        self::assertSame('Sentença publicada. · 2 ponto(s)', trim($previa->text()));
        self::assertSame('0', $cartao->filter("form#ps-ia-recolher-{$id} input[name=recolhida]")->attr('value'));

        // Recolher de novo é idempotente: a lista não repete o id.
        $this->recolher($client, (int) $pasta->getId(), $id, '1');
        self::assertSame([$id], $this->gravado($tenant, $user));

        $this->recolher($client, (int) $pasta->getId(), $id, '0');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->gravado($tenant, $user));
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");
        self::assertSame('true', $this->cartao($crawler, $id)->filter('.ps-ia-cab > button[data-ia-recolher]')->attr('aria-expanded'));
    }

    #[TestDox('a escolha é POR usuário: o colega do mesmo escritório continua vendo a análise aberta')]
    public function testPreferenciaPorUsuario(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise);
        $colega = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'colega');
        $this->logarComTenant($client, $user, $tenant);
        $this->recolher($client, (int) $pasta->getId(), (int) $analise->getId(), '1');
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $colega, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");

        self::assertResponseIsSuccessful();
        self::assertSame('true', $this->cartao($crawler, (int) $analise->getId())->filter('.ps-ia-cab > button[data-ia-recolher]')->attr('aria-expanded'));
        self::assertNull($this->gravado($tenant, $colega));
    }

    #[TestDox('outro escritório (pasta ou análise) e pasta irmã → 404 e NADA gravado em preferencia_usuario')]
    public function testIsolamento(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA] = $this->criarPastaComPublicacao($tenantA);
        [$pastaA2] = $this->criarPastaComPublicacao($tenantA, '07099999999999999999');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $analiseB = $this->criarAnalisePendente($tenantB, $userB, $pastaB);
        $this->concluirAnalise($analiseB);
        $analiseA2 = $this->criarAnalisePendente($tenantA, $userA, $pastaA2);
        $this->concluirAnalise($analiseA2);
        $this->logarComTenant($client, $userA, $tenantA);

        $this->recolher($client, (int) $pastaB->getId(), (int) $analiseB->getId(), '1');
        self::assertResponseStatusCodeSame(404, 'pasta de outro escritório');

        $this->recolher($client, (int) $pastaA->getId(), (int) $analiseB->getId(), '1');
        self::assertResponseStatusCodeSame(404, 'análise de outro escritório pela própria pasta');

        $this->recolher($client, (int) $pastaA->getId(), (int) $analiseA2->getId(), '1');
        self::assertResponseStatusCodeSame(404, 'pasta irmã do mesmo escritório');

        self::assertSame(0, $this->linhasDePreferencia(), 'nenhuma tentativa recusada grava preferência');

        // O mesmo id pela pasta certa: senão os 404 acima passariam por engano.
        $this->recolher($client, (int) $pastaA2->getId(), (int) $analiseA2->getId(), '1');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('sem CSRF → 403; sem modules.inteligencia.view → 403; nada gravado')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $admin, $pasta);
        $this->concluirAnalise($analise);
        $this->logarComTenant($client, $admin, $tenant);

        $this->recolher($client, (int) $pasta->getId(), (int) $analise->getId(), '1', comToken: false);
        self::assertResponseStatusCodeSame(403);

        $semModulo = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $semModulo, $tenant);
        $this->recolher($client, (int) $pasta->getId(), (int) $analise->getId(), '1');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->linhasDePreferencia());
    }

    #[TestDox('análise em andamento não tem chevron nem prévia (só a concluída recolhe)')]
    public function testEmAndamentoNaoRecolhe(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");

        $cartao = $this->cartao($crawler, (int) $analise->getId());
        self::assertSame(1, $cartao->count());
        self::assertSame(0, $cartao->filter('[data-ia-recolher], .ps-ia-previa, form[data-ia-acao="recolher"]')->count());
    }
}
