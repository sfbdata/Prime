<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\AnaliseDaPastaController;
use App\Pasta\Entity\AbaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A aba Documentos do drawer BlueJus IA (spec inteligencia-drawer-da-pasta, F4, e PR F da spec
 * ia-anthropic-ligar; desenho dc L1044-1058): o texto honesto do topo (lê PDF e imagem, sem prometer
 * OCR), o cartão da lista ("Selecionar todos", divisória, uma linha por documento com caixa, ícone pelo
 * tipo, nome e dados) e a grade de dois botões do desenho: "Ler N documentos" (CTA cheio, "Selecione"
 * sem seleção) e "Índice inteligente" (secundário), os dois desabilitados sem seleção e apontando para a
 * sua seção. Com o aceite anterior ao texto que cita os documentos, o "Ler" fica bloqueado com aviso.
 *
 * Arranjo com combinador de FILHO DIRETO a partir do painel da aba. A seleção e o envio são do JS,
 * que não roda aqui: o que se prova é o HTML de onde ele parte.
 */
#[CoversClass(AnaliseDaPastaController::class)]
final class DocumentosDoIndiceTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const DRAWER = 'aside.ia-drawer#psIaDrawer[data-ia-drawer]';
    private const ABA_NA_PASTA = self::DRAWER . ' > .ia-drawer-corpo > .ia-agentes[data-ia-painel] > .ia-aba-painel[data-ia-aba-painel="documentos"]';
    private const ABA_NO_PAINEL = '.ia-agentes[data-ia-painel] > .ia-aba-painel[data-ia-aba-painel="documentos"]';
    private const TEXTO_DO_TOPO = 'A IA lê os arquivos PDF e imagem que você marcar (até 3 por vez, 10 MB por arquivo, imagem até 3 MB e 100 páginas no total) e extrai tipo, data, partes, valores, prazos e providências. O Índice inteligente usa só o nome, o título, a categoria e a data de cada arquivo.';
    private const ACOES = ' > .ia-docs-acoes';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function painel(KernelBrowser $client, int $pastaId): Crawler
    {
        $crawler = $client->request('GET', "/pasta/{$pastaId}/ia/agentes");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Nenhum texto promete o OCR do desenho (spec ia-anthropic-ligar §3.1). */
    private static function semPromessaDeLeitura(string $html): void
    {
        self::assertDoesNotMatchRegularExpression('/\bOCR\b|digitalizados/u', $html);
    }

    #[TestDox('HTML da pasta: aba Documentos com o texto do topo, a lista "carregando", a grade "Selecione" + "Índice inteligente" desabilitada e as duas seções sem botão nem cabeçalho')]
    public function testAbaNoHtmlDaPasta(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertSame(self::TEXTO_DO_TOPO, trim($crawler->filter(self::ABA_NA_PASTA . ' > p.ia-aba-intro')->text()));
        self::assertSame(1, $crawler->filter(self::ABA_NA_PASTA . ' > .ia-docs[data-ia-docs] > [data-ia-docs-carregando]')->count());
        // A grade do desenho (dc L1053-1056): Ler (CTA cheio) e Índice (secundário), nessa ordem.
        $botoes = $crawler->filter(self::ABA_NA_PASTA . self::ACOES . ' > button.ia-cta[data-ia-gerar][data-ia-exige-selecao]');
        self::assertSame(['ler_documentos', 'indice_documentos'], $botoes->each(static fn (Crawler $b): ?string => $b->attr('data-ia-secao')));
        $ler = $botoes->eq(0);
        self::assertStringNotContainsString('ia-cta--secundario', (string) $ler->attr('class'));
        self::assertNotNull($ler->attr('data-ia-ler'));
        self::assertSame('Selecione', trim($ler->text()), 'sem seleção, o rótulo do desenho (`lerTxt`)');
        self::assertSame(1, $ler->filter('i.bi-stars')->count());
        self::assertNotNull($ler->attr('disabled'));
        self::assertSame("/pasta/{$pasta->getId()}/ia/comandos/ler_documentos/analises", $ler->attr('data-ia-url'));
        $botao = $botoes->eq(1);
        self::assertStringContainsString('ia-cta--secundario', (string) $botao->attr('class'));
        self::assertSame('Índice inteligente', trim($botao->text()));
        self::assertSame(1, $botao->filter('i.bi-list-columns-reverse')->count());
        self::assertNotNull($botao->attr('disabled'), 'nasce desabilitado: nenhum documento marcado');
        self::assertSame("/pasta/{$pasta->getId()}/ia/comandos/indice_documentos/analises", $botao->attr('data-ia-url'));

        $secao = $crawler->filter(self::ABA_NA_PASTA . ' > section.ia-comando.ia-comando--indice[data-ia-comando="indice_documentos"]');
        self::assertSame(1, $secao->count());
        self::assertNull($secao->attr('hidden'), 'a seção do Índice abre primeiro');
        self::assertSame(0, $secao->filter('.ia-agente-cab, button[data-ia-gerar]')->count(), 'sem cabeçalho e sem botão: o botão mora na grade');
        self::assertStringContainsString('Agente Documental. Lê: dados da pasta, processos, documentos (só nomes e datas), checklist.', $secao->filter('.ia-agente-le')->text());
        $leitura = $crawler->filter(self::ABA_NA_PASTA . ' > section.ia-comando.ia-comando--indice[data-ia-comando="ler_documentos"]');
        self::assertSame(1, $leitura->count());
        self::assertNotNull($leitura->attr('hidden'));
        self::assertStringContainsString('Lê: o conteúdo dos documentos marcados (PDF e imagem)', $leitura->filter('.ia-agente-le')->text());
        self::assertSame(0, $crawler->filter('[data-ia-aviso-aceite]')->count(), 'aceite dado agora: sem aviso');
        self::semPromessaDeLeitura($crawler->filter(self::DRAWER)->html());
    }

    #[TestDox('Painel: "Selecionar todos" com "0 de N", divisória e uma linha por documento DESTA pasta (caixa, ícone pelo tipo, nome, "1 KB · hoje"), mais recente primeiro')]
    public function testPainelComDocumentos(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [, $tenantB] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        [$outraPasta] = $this->criarPastaComPublicacao($tenant, '07099995520258070001');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->ligarIaNoTenant($tenant, $user);
        $antigo = $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $novo = $this->criarDocumento($tenant, $pasta, 'Peticao inicial');
        $this->criarDocumento($tenant, $outraPasta, 'Da outra pasta');
        $this->criarDocumento($tenantB, $pastaB, 'Do outro escritorio');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->painel($client, (int) $pasta->getId());

        $cartao = self::ABA_NO_PAINEL . ' > .ia-docs[data-ia-docs]';
        $todos = $crawler->filter($cartao . ' > button.ia-doc.ia-doc--todos[data-ia-docs-todos]');
        self::assertSame(1, $todos->count());
        self::assertSame('false', $todos->attr('aria-pressed'));
        self::assertSame('Selecionar todos', trim($todos->filter('.ia-doc-todos-rotulo')->text()));
        self::assertSame('0 de 2', trim($todos->filter('[data-ia-docs-contagem]')->text()));
        self::assertSame(1, $todos->filter('.ia-doc-check > i.bi-check-lg')->count());
        self::assertSame(1, $crawler->filter($cartao . ' > button[data-ia-docs-todos] + .ia-docs-divisoria')->count(), 'a divisória vem logo depois de "Selecionar todos"');

        $linhas = $crawler->filter($cartao . ' > button.ia-doc[data-ia-doc]');
        self::assertSame([(string) $novo->getId(), (string) $antigo->getId()], $linhas->each(static fn (Crawler $l): ?string => $l->attr('data-ia-doc')));
        $primeira = $linhas->eq(0);
        self::assertSame('false', $primeira->attr('aria-pressed'));
        self::assertSame(1, $primeira->filter('button > .ia-doc-check > i.bi-check-lg')->count());
        self::assertSame(1, $primeira->filter('button > .ia-doc-ico.ia-doc-ico--pdf > i.bi-file-earmark-pdf-fill')->count());
        self::assertSame('peticao-inicial.pdf', trim($primeira->filter('button > .ia-doc-txt > .ia-doc-nome')->text()));
        self::assertSame('1 KB · hoje', trim($primeira->filter('button > .ia-doc-txt > .ia-doc-dados')->text()));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('da-outra-pasta.pdf', $html);
        self::assertStringNotContainsString('do-outro-escritorio.pdf', $html);
        self::assertStringNotContainsString('teste/', $html, 'o caminho no armazenamento não sai');
        self::assertSame(1, $crawler->filter(self::ABA_NO_PAINEL . ' > section.ia-comando--indice > .ia-ag-lista[data-ia-lista="indice_documentos"] > [data-ia-vazio]')->count());
        self::semPromessaDeLeitura($html);
    }

    #[TestDox('Painel de pasta sem documento: "Nenhum documento nesta pasta." e o botão desabilitado')]
    public function testPainelSemDocumentos(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->painel($client, (int) $pasta->getId());

        self::assertSame('Nenhum documento nesta pasta.', trim($crawler->filter(self::ABA_NO_PAINEL . ' > .ia-docs > [data-ia-docs-vazio]')->text()));
        self::assertSame(0, $crawler->filter('[data-ia-doc], [data-ia-docs-todos]')->count());
        $crawler->filter(self::ABA_NO_PAINEL . self::ACOES . ' > button[data-ia-gerar]')->each(static function (Crawler $b): void {
            self::assertNotNull($b->attr('disabled'));
        });
    }

    #[TestDox('PR F: aceite do escritório anterior ao texto que cita os documentos: o "Ler" bloqueado com o aviso, o Índice continua')]
    public function testAceiteAntigoBloqueiaOLer(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $configuracao = $this->ligarIaNoTenant($tenant, $user);
        $this->envelhecerAceite($configuracao);
        $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->painel($client, (int) $pasta->getId());

        $ler = $crawler->filter(self::ABA_NO_PAINEL . self::ACOES . ' > button.ia-cta--indisponivel[data-ia-secao="ler_documentos"]');
        self::assertSame(1, $ler->count());
        self::assertSame('aceite_desatualizado', $ler->attr('data-ia-motivo'));
        self::assertNotNull($ler->attr('disabled'));
        self::assertNull($ler->attr('data-ia-url'), 'sem a rota do pedido');
        self::assertSame(1, $crawler->filter(self::ABA_NO_PAINEL . self::ACOES . ' > button[data-ia-secao="indice_documentos"][data-ia-url]')->count(), 'o Índice não depende do aceite novo');
        self::assertStringContainsString('o administrador renova o aceite', $crawler->filter(self::ABA_NO_PAINEL . ' > p.ia-docs-aceite[data-ia-aviso-aceite]')->text());
    }

    #[TestDox('IA indisponível: o botão do Índice carrega o motivo real, desabilitado e sem a rota do pedido')]
    public function testIndisponivel(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->provedorFalso()->desconfigurar();
        $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->painel($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::ABA_NO_PAINEL . self::ACOES . ' > button.ia-cta--indisponivel[data-ia-gerar][data-ia-secao="indice_documentos"]');
        self::assertSame(1, $botao->count());
        self::assertSame(2, $crawler->filter(self::ABA_NO_PAINEL . self::ACOES . ' > button.ia-cta--indisponivel[data-ia-gerar]')->count(), 'o Ler também');
        self::assertNotNull($botao->attr('disabled'));
        self::assertSame('nao_configurada_na_plataforma', $botao->attr('data-ia-motivo'));
        self::assertStringContainsString('IA não configurada nesta instalação', $botao->text());
        self::assertNull($botao->attr('data-ia-url'));
    }

    #[TestDox('Aba Documentos da pasta oculta para quem vê: a aba some do drawer (botão e painel), e as outras continuam na ordem')]
    public function testAbaOcultaSome(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $this->criarDocumento($tenant, $pasta, 'Procuracao');
        $comum = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view']);
        $this->ocultarAbaParaTodos($pasta, AbaDaPasta::Documentos, $admin);
        $this->logarComTenant($client, $comum, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $abas = $crawler->filter(self::DRAWER . ' > .ia-drawer-cab > .ia-abas[role="tablist"] > button.ia-aba[role="tab"]');
        self::assertSame(['analise', 'relatorios', 'perguntar'], $abas->each(static fn (Crawler $a): ?string => $a->attr('data-ia-aba')));
        self::assertSame(0, $crawler->filter(self::ABA_NA_PASTA)->count());
        self::assertSame(0, $crawler->filter('[data-ia-comando="indice_documentos"]')->count());
    }
}
