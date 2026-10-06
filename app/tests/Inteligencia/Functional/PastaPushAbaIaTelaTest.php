<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A BlueJus IA na aba Push da pasta (spec inteligencia-resumo-do-push §3.6 e §4.2).
 *
 * O que importa: o botão nunca finge funcionar. Disponível → habilitado; em qualquer outro estado,
 * desabilitado com o motivo REAL da `Disponibilidade`. Sem `modules.inteligencia.view`, nada de IA.
 *
 * Arranjo com combinador de FILHO DIRETO (`.ps-push > .ps-card-cab > .ps-ia-gerar`): "existe na
 * página" seria verdade com o botão no lugar errado. Estilo e JS (polling, menu, modal) não são
 * visíveis aqui — ficam para o smoke na tela.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushAbaIaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const BOTAO = '#push > .ps-push > .ps-card-cab > button.ps-ia-gerar';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        // O estado do ProvedorFalso precisa ser o mesmo que a requisição enxerga.
        $client->disableReboot();

        return $client;
    }

    private function abrirPasta(KernelBrowser $client, int $pastaId): Crawler
    {
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('Sem provedor: botão no cabeçalho do card, desabilitado, com "IA não configurada nesta instalação"')]
    public function testSemProvedorBotaoDesabilitadoComMotivo(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->provedorFalso()->desconfigurar();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertSame(1, $botao->count(), 'o botão mora no cabeçalho do card (filho direto)');
        self::assertNotNull($botao->attr('disabled'), 'sem provedor o botão não pode ser clicável');
        self::assertSame('0', $botao->attr('data-ia-disponivel'));
        self::assertSame('nao_configurada_na_plataforma', $botao->attr('data-ia-motivo'));
        self::assertStringContainsString('IA não configurada nesta instalação', $botao->text());
        self::assertNull($botao->attr('data-ia-solicitar-url'), 'desabilitado não carrega a rota do pedido');
        self::assertStringNotContainsString('Resumir com IA', $botao->text());
    }

    #[TestDox('Com provedor e o escritório ligado: "Resumir com IA" habilitado, entre o atalho e os filtros')]
    public function testDisponivelBotaoHabilitado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertSame(1, $botao->count());
        self::assertNull($botao->attr('disabled'));
        self::assertSame('1', $botao->attr('data-ia-disponivel'));
        self::assertStringContainsString('Resumir com IA', $botao->text());
        self::assertSame('A IA lê as movimentações e indica prazos e providências', $botao->attr('title'));
        self::assertSame("/pasta/{$pasta->getId()}/ia/push/analises", $botao->attr('data-ia-solicitar-url'));
        self::assertNotEmpty($botao->attr('data-ia-token'));

        // Posição do desenho (dc L.2416): imediatamente antes dos filtros.
        self::assertSame(1, $crawler->filter('#push > .ps-push > .ps-card-cab > .ps-ia-gerar + .ps-push-filtros')->count());

        // A lista (ainda vazia) vem logo abaixo do cabeçalho; o script e a folha da IA são carregados.
        self::assertSame(1, $crawler->filter('#push > .ps-push > .ps-card-cab + .ps-ia-msg[hidden] + .ps-ia-lista[data-ia-lista]')->count());
        self::assertSame(0, $crawler->filter('.ps-ia-lista > .ps-ia-cartao')->count());
        self::assertSame(1, $crawler->filter('#push > script[src*="js/pasta-ia-push.js"]')->count());
        self::assertSame(1, $crawler->filter('#push > link[href*="css/pasta-ia.css"]')->count());
    }

    #[TestDox('Com análise concluída: "Gerar nova análise" e o cartão com o selo de IA')]
    public function testComAnaliseConcluida(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta, ['chave-que-nao-existe']);
        $this->concluirAnalise($analise, 'Sentença publicada; cabe apelação.', [
            ['tipo' => 'prazo', 'texto' => 'Apelação em 15 dias úteis.'],
        ], 'Advogado do autor');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertNull($botao->attr('disabled'));
        self::assertStringContainsString('Gerar nova análise', $botao->text());
        self::assertMatchesRegularExpression('/^\d+ movimentação\(ões\) ainda não analisada\(s\)$/', (string) $botao->attr('title'));

        $cartao = $crawler->filter('#push > .ps-push > .ps-ia-lista > .ps-ia-cartao[data-ia-analise="' . $analise->getId() . '"]');
        self::assertSame(1, $cartao->count());
        self::assertSame('✦ Análise IA', trim($cartao->filter('.ps-ia-corpo > .ps-ia-cab > .ps-ia-selo')->text()));
        self::assertStringContainsString(
            'Gerado por IA · não é movimentação oficial',
            $cartao->filter('.ps-ia-corpo > .ps-ia-cab > .ps-ia-aviso')->text(),
        );
        // A entidade pode normalizar o texto: compara com o que ela devolve.
        self::assertSame((string) $analise->getResumo(), trim($cartao->filter('.ps-ia-corpo > .ps-ia-resumo')->text()));
        self::assertSame(1, $cartao->filter('.ps-ia-pontos > .ps-ia-ponto--prazo')->count());
        self::assertSame(1, $cartao->filter('.ps-ia-menu [data-ia-criar-meta]')->count(), 'criar tarefa a partir da providência');
        self::assertSame(1, $cartao->filter('.ps-ia-cab form[data-ia-acao="interna"]')->count());
    }

    #[TestDox('Análise em andamento: botão "Analisando…" travado e a lista marcada para o polling')]
    public function testEmAndamento(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertNotNull($botao->attr('disabled'));
        self::assertStringContainsString('Analisando…', $botao->text());
        self::assertSame('1', $crawler->filter('.ps-ia-lista')->attr('data-ia-em-andamento'));
        self::assertSame(1, $crawler->filter('.ps-ia-cartao[data-ia-status="pendente"] .ps-ia-andamento')->count());
    }

    #[TestDox('Escritório com a IA desligada: botão desabilitado com "desligada neste escritório"')]
    public function testTenantDesligado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        // Sem configuração do escritório = desligada (default seguro).
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertSame(1, $botao->count());
        self::assertNotNull($botao->attr('disabled'));
        self::assertSame('desligada_no_escritorio', $botao->attr('data-ia-motivo'));
        self::assertStringContainsString('desligada neste escritório', $botao->text());
    }

    #[TestDox('Sem modules.inteligencia.view: nada de IA na aba — nem botão, nem lista, nem script')]
    public function testSemPermissaoNadaDeIa(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $analise = $this->criarAnalisePendente($tenant, $admin, $pasta);
        $this->concluirAnalise($analise);
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        self::assertSame(1, $crawler->filter('#push > .ps-push')->count(), 'a aba Push continua lá');
        self::assertSame(1, $crawler->filter('.ps-push-lista > .ps-push-item')->count(), 'e as publicações também');
        self::assertSame(0, $crawler->filter('.ps-ia-gerar')->count());
        self::assertSame(0, $crawler->filter('.ps-ia-lista')->count());
        self::assertSame(0, $crawler->filter('.ps-ia-cartao')->count());
        self::assertSame(0, $crawler->filter('script[src*="pasta-ia-push.js"]')->count());
        self::assertStringNotContainsString('Análise IA', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Análise de OUTRO escritório apontando para o id desta pasta não aparece na aba')]
    public function testAnaliseDeOutroTenantNaoVaza(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA] = $this->criarPastaComPublicacao($tenantA);
        $this->ligarIaNoTenant($tenantA, $userA);
        // Recurso irmão: a MESMA pasta/alvo, só que gravada sob o escritório B.
        $alheia = $this->criarAnalisePendente($tenantB, $userB, $pastaA);
        $this->concluirAnalise($alheia, 'Resumo do outro escritório.');
        $this->logarComTenant($client, $userA, $tenantA);

        $crawler = $this->abrirPasta($client, (int) $pastaA->getId());

        self::assertSame(1, $crawler->filter('#push > .ps-push > .ps-ia-lista')->count(), 'a lista existe…');
        self::assertSame(0, $crawler->filter('.ps-ia-cartao')->count(), '…mas sem a análise alheia');
        self::assertStringContainsString('Resumir com IA', $crawler->filter(self::BOTAO)->text(), 'nem conta como análise desta pasta');
        self::assertStringNotContainsString((string) $alheia->getResumo(), (string) $client->getResponse()->getContent());
    }
}
