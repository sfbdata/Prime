<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\AnalisePushController;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\UseCase\ListarAuditoriaDaPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * IA-008: rodapé "Registro de auditoria (N)" do drawer. A trilha é `inteligencia_analise` DESTA
 * pasta, deste escritório: nada de outro escritório nem de pasta irmã entra; a excluída fica,
 * marcada; nomes saem escapados; nenhum texto do modelo aparece.
 */
#[CoversClass(AnalisePushController::class)]
#[CoversClass(ListarAuditoriaDaPastaUseCase::class)]
final class AuditoriaDaPastaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const RODAPE = 'footer.ia-drawer-audit[data-ia-auditoria]';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    #[TestDox('lista Push e agentes desta pasta, conta N, marca a excluída e não mostra texto do modelo')]
    public function testListaATrilhaDaPasta(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $user->setFullName('Ana <b>Lima</b>');
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $push = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($push, 'Resumo SIGILOSO do Push.');
        $gestor = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $excluida = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);
        $this->concluirAnalise($excluida, 'Resumo.', [], null, 'Texto SIGILOSO do agente.');
        $excluida->excluir($user);
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/auditoria");

        self::assertResponseIsSuccessful();
        $rodape = $crawler->filter(self::RODAPE);
        self::assertSame(1, $rodape->count());
        self::assertSame('1', $rodape->attr('data-ia-carregado'));
        self::assertSame('Registro de auditoria (3)', trim($rodape->filter('.ia-audit-botao > .ia-audit-rotulo')->text()));

        $linhas = $rodape->filter('.ia-audit-lista > .ia-audit-linha');
        self::assertSame(3, $linhas->count());
        $porId = [];
        $linhas->each(static function (Crawler $l) use (&$porId): void {
            $porId[(int) $l->attr('data-ia-audit-analise')] = $l->filter('.ia-audit-txt')->text();
        });
        self::assertStringContainsString('Análise IA do Push · Concluída', $porId[$push->getId()]);
        self::assertStringContainsString('Agente Gestor · Na fila', $porId[$gestor->getId()]);
        self::assertStringContainsString('Agente Prazos', $porId[$excluida->getId()]);
        self::assertStringContainsString('excluída da tela', $porId[$excluida->getId()]);
        self::assertStringNotContainsString('excluída', $porId[$gestor->getId()]);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('pedida por Ana &lt;b&gt;Lima&lt;/b&gt;', $html, 'o nome sai escapado');
        self::assertStringNotContainsString('<b>Lima</b>', $html);
        self::assertStringNotContainsString('SIGILOSO', $html, 'a trilha é só metadado: nenhum texto do modelo');
    }

    #[TestDox('outro escritório e pasta irmã não vazam; a pasta de outro escritório responde 404')]
    public function testIsolamento(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA] = $this->criarPastaComPublicacao($tenantA);
        [$pastaA2] = $this->criarPastaComPublicacao($tenantA, '07099999999999999999');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $daA = $this->criarAnalisePendente($tenantA, $userA, $pastaA);
        $irma = $this->criarAnalisePendente($tenantA, $userA, $pastaA2);
        $deB = $this->criarAnalisePendente($tenantB, $userB, $pastaB);
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/auditoria");
        self::assertResponseStatusCodeSame(404);

        $crawler = $client->request('GET', "/pasta/{$pastaA->getId()}/ia/auditoria");
        self::assertResponseIsSuccessful();
        self::assertSame('Registro de auditoria (1)', trim($crawler->filter('.ia-audit-rotulo')->text()));
        self::assertSame(
            [(string) $daA->getId()],
            $crawler->filter('.ia-audit-linha')->each(static fn (Crawler $l): ?string => $l->attr('data-ia-audit-analise')),
        );
        self::assertSame(0, $crawler->filter('[data-ia-audit-analise="' . $irma->getId() . '"]')->count());
        self::assertSame(0, $crawler->filter('[data-ia-audit-analise="' . $deB->getId() . '"]')->count());
    }

    #[TestDox('pasta sem nenhuma ação de IA: N = 0 e a frase de vazio')]
    public function testVazio(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/auditoria");

        self::assertSame('Registro de auditoria (0)', trim($crawler->filter('.ia-audit-rotulo')->text()));
        self::assertStringContainsString('Nenhuma ação da IA nesta pasta ainda.', $crawler->filter('.ia-audit-lista')->text());
    }

    #[TestDox('sem modules.inteligencia.view → 403')]
    public function testSemModulo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/auditoria");

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('no drawer da pasta, o rodapé nasce sem contagem, fechado, filho direto do drawer')]
    public function testRodapeNoDrawer(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $rodape = $crawler->filter('aside.ia-drawer#psIaDrawer > ' . self::RODAPE);
        self::assertSame(1, $rodape->count(), 'o rodapé é filho direto do drawer, depois do corpo');
        self::assertSame('0', $rodape->attr('data-ia-carregado'));
        self::assertSame("/pasta/{$pasta->getId()}/ia/auditoria", $rodape->attr('data-ia-auditoria-url'));
        self::assertSame('Registro de auditoria', trim($rodape->filter('.ia-audit-rotulo')->text()));
        self::assertSame('false', $rodape->filter('button.ia-audit-botao')->attr('aria-expanded'));
        self::assertNotNull($rodape->filter('.ia-audit-lista')->attr('hidden'));
    }
}
