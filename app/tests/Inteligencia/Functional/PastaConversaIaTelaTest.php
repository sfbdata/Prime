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
 * A aba "Perguntar" no HTML da pasta (spec inteligencia-drawer-da-pasta, F3; desenho dc L1072-1092 e
 * L1126-1131). Arranjo com combinador de FILHO DIRETO: o painel da aba é filho do corpo do drawer
 * (fora do painel que o JS troca inteiro) e o rodapé do campo é filho do drawer, entre o corpo e o
 * Registro de auditoria, escondido junto com a aba. IA indisponível: campo e botão desabilitados com
 * o motivo real. Estilo e JS não são visíveis aqui: o resto é smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaConversaIaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const DRAWER = 'aside.ia-drawer#psIaDrawer[data-ia-drawer]';
    private const PAINEL = self::DRAWER . ' > .ia-drawer-corpo > .ia-aba-painel#ia-aba-perguntar[role="tabpanel"][data-ia-aba-painel="perguntar"]';
    private const RODAPE = self::DRAWER . ' > .ia-conversa-rodape[data-ia-conversa-rodape][data-ia-aba-painel="perguntar"]';

    private function abrirPasta(bool $comProvedor = true): Crawler
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        if (!$comProvedor) {
            $this->provedorFalso()->desconfigurar();
        }
        $this->logarComTenant($client, $user, $tenant);

        return $this->pagina($client, (int) $pasta->getId());
    }

    private function pagina(KernelBrowser $client, int $pastaId): Crawler
    {
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('painel da aba Perguntar: filho direto do corpo do drawer, nasce oculto e "carregando", com a URL da conversa')]
    public function testPainelDaAba(): void
    {
        $crawler = $this->abrirPasta();

        $painel = $crawler->filter(self::PAINEL);
        self::assertSame(1, $painel->count());
        self::assertNotNull($painel->attr('hidden'), 'a aba ativa ao abrir é Análise');
        self::assertSame('ia-aba-btn-perguntar', $painel->attr('aria-labelledby'));
        self::assertMatchesRegularExpression('#^/pasta/\d+/ia/conversa$#', (string) $painel->attr('data-ia-conversa-url'));
        self::assertSame(1, $crawler->filter(self::PAINEL . ' > .ia-conversa[data-ia-conversa][data-ia-carregado="0"]')->count());
        self::assertSame(0, $crawler->filter(self::DRAWER . ' > .ia-drawer-corpo > .ia-agentes [data-ia-aba-painel="perguntar"]')->count(), 'fora do painel que o JS troca inteiro');
        self::assertSame(1, $crawler->filter('script[src="/js/pasta-ia-conversa.js"]')->count());
    }

    #[TestDox('rodapé do campo: filho direto do drawer, entre o corpo e o Registro de auditoria, oculto fora da aba; campo e botão do desenho')]
    public function testRodape(): void
    {
        $crawler = $this->abrirPasta();

        $rodape = $crawler->filter(self::RODAPE);
        self::assertSame(1, $rodape->count());
        self::assertNotNull($rodape->attr('hidden'));
        self::assertSame('1', $rodape->attr('data-ia-disponivel'));
        self::assertMatchesRegularExpression('#^/pasta/\d+/ia/conversa/perguntas$#', (string) $rodape->attr('data-ia-perguntar-url'));

        $filhos = $crawler->filter(self::DRAWER)->children()->each(static fn (Crawler $f): string => (string) $f->attr('class'));
        $corpo = array_search('ia-drawer-corpo', $filhos, true);
        $doRodape = array_search('ia-conversa-rodape', $filhos, true);
        self::assertIsInt($corpo);
        self::assertSame($corpo + 1, $doRodape, 'o rodapé vem logo depois do corpo');
        self::assertStringContainsString('ia-drawer-audit', $filhos[$doRodape + 1] ?? '', 'e antes do Registro de auditoria');

        $campo = $crawler->filter(self::RODAPE . ' > .ia-conversa-campo > input.ia-conversa-input[data-ia-conversa-input]');
        self::assertSame(1, $campo->count());
        self::assertSame('Pergunte sobre esta pasta...', $campo->attr('placeholder'));
        self::assertSame('Pergunta para a IA', $campo->attr('aria-label'));
        self::assertSame('2000', $campo->attr('maxlength'));
        self::assertNull($campo->attr('disabled'));

        $botao = $crawler->filter(self::RODAPE . ' > .ia-conversa-campo > button.ia-conversa-enviar[data-ia-conversa-enviar]');
        self::assertSame(1, $botao->count());
        self::assertSame('Enviar', $botao->attr('aria-label'));
        self::assertSame(1, $botao->filter('i.bi-send-fill')->count());
        self::assertNotNull($botao->attr('disabled'), 'campo vazio: nasce desabilitado');
        self::assertNotNull($crawler->filter(self::RODAPE . ' > p.ia-conversa-aviso')->attr('hidden'));
    }

    #[TestDox('IA indisponível: campo e botão desabilitados, com o motivo real escrito no rodapé')]
    public function testIndisponivel(): void
    {
        $crawler = $this->abrirPasta(comProvedor: false);

        self::assertSame('0', $crawler->filter(self::RODAPE)->attr('data-ia-disponivel'));
        $campo = $crawler->filter(self::RODAPE . ' > .ia-conversa-campo > input.ia-conversa-input');
        self::assertNotNull($campo->attr('disabled'));
        self::assertSame('IA não configurada nesta instalação', $campo->attr('title'));
        $botao = $crawler->filter(self::RODAPE . ' > .ia-conversa-campo > button.ia-conversa-enviar');
        self::assertNotNull($botao->attr('disabled'));
        self::assertSame('IA não configurada nesta instalação', $botao->attr('title'));
        $aviso = $crawler->filter(self::RODAPE . ' > p.ia-conversa-aviso[data-ia-conversa-aviso]');
        self::assertNull($aviso->attr('hidden'));
        self::assertSame('IA não configurada nesta instalação', trim($aviso->text()));
    }

    #[TestDox('nenhum texto visível da aba usa travessão')]
    public function testSemTravessao(): void
    {
        $crawler = $this->abrirPasta();

        $texto = $crawler->filter(self::PAINEL)->text() . $crawler->filter(self::RODAPE)->html();
        self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $texto);
    }
}
