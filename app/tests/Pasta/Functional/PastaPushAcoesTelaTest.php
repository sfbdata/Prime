<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Djen\Entity\PublicacaoDjen;
use App\Pasta\Controller\PastaPushProcessualController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote L3 da aba Push (desenho 1.2.3): filtro Todas · Novas no cabeçalho, linha do documento
 * (abrir no PJe, copiar ID) e ações (criar tarefa, marcar como não lida) no teor.
 *
 * Arranjo com combinador de FILHO DIRETO: "existe na página" seria verdade com o botão no lugar
 * errado. Estilo e comportamento do JS (o filtro esconder linhas, o modal abrir preenchido) não
 * são visíveis aqui — ficam para o smoke na tela.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(PastaPushProcessualController::class)]
#[Group('pasta')]
final class PastaPushAcoesTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const LINK_PJE        = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao/abc123/certidao';

    private function documentar(PublicacaoDjen $pub, ?string $numeroComunicacao, ?string $link): void
    {
        $pub->setNumeroComunicacao($numeroComunicacao);
        $pub->setLink($link);
        $this->em()->flush();
    }

    #[TestDox('Cabeçalho da aba: filtros Todas e Novas, filhos diretos, com Todas ativo')]
    public function testFiltrosNoCabecalho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '50000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        $filtros = $crawler->filter('#push > .ps-push > .ps-card-cab > .ps-push-filtros > .ps-push-filtro');
        self::assertSame(['todas', 'novas', 'prazo'], $filtros->each(static fn ($b) => $b->attr('data-push-filtro')));
        self::assertSame(['Todas', 'Novas', 'Geram prazo'], $filtros->each(static fn ($b) => trim($b->text())));
        self::assertSame('true', $crawler->filter('.ps-push-filtro[data-push-filtro="todas"]')->attr('aria-pressed'));
        self::assertSame('false', $crawler->filter('.ps-push-filtro[data-push-filtro="novas"]')->attr('aria-pressed'));
        self::assertSame('false', $crawler->filter('.ps-push-filtro[data-push-filtro="prazo"]')->attr('aria-pressed'));
        // O vazio do filtro existe e nasce escondido; o script da aba é carregado.
        self::assertSame(1, $crawler->filter('#push > .ps-push > #push-vazio-filtro[hidden]')->count());
        self::assertSame(1, $crawler->filter('#push > script[src*="js/pasta-push.js"]')->count());
    }

    #[TestDox('Sem publicação não há filtro: filtrar uma lista vazia não tem sentido')]
    public function testSemPublicacaoNaoHaFiltro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.ps-push-filtros')->count());
        self::assertSame(1, $crawler->filter('#push-vazio-sem-publicacao')->count());
    }

    #[TestDox('Itens: cada linha é filha direta da lista, marca nova/lida e leva a pílula do dia (repetida escondida)')]
    public function testItensDaLista(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $lida = $this->criarPublicacao($tenant, '50000010', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $lida->setLida(true);
        $this->em()->flush();
        $this->criarPublicacao($tenant, '50000011', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->criarPublicacao($tenant, '50000012', self::NUMERO_DA_PASTA, '2026-08-28', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        $itens = $crawler->filter('.ps-push > .ps-push-lista > .ps-push-item');
        self::assertSame(3, $itens->count());
        self::assertSame(2, $crawler->filter('.ps-push-lista > .ps-push-item.ps-push-item--nova')->count(), '"Novas" = não lidas');
        self::assertSame('2', trim($crawler->filter('#pastaTabs > #push-tab > .ps-aba-badge')->text()), 'o filtro e o selo leem o mesmo dado');

        // Pílula em toda linha, para o filtro poder esconder a primeira do dia sem levar a data.
        self::assertSame(3, $crawler->filter('.ps-push-lista > .ps-push-item > .ps-push-dia')->count());
        self::assertSame(1, $crawler->filter('.ps-push-lista > .ps-push-item > .ps-push-dia.ps-push-dia--repetido')->count(), 'só a segunda do dia 20/08 é repetida');
        // `data-push-dia` é o que o JS compara para recalcular a primeira visível de cada dia.
        $dias = $itens->each(static fn ($li) => (string) $li->attr('data-push-dia'));
        sort($dias);
        self::assertSame(['20/08/2026', '20/08/2026', '28/08/2026'], $dias);
    }

    #[TestDox('Teor: "ID - tipo" abre o documento no PJe em nova aba e o clipboard copia o ID')]
    public function testLinhaDoDocumento(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '50000020', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->documentar($pub, '987654', self::LINK_PJE);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseIsSuccessful();
        $doc = $crawler->filter('.ps-push-teor-corpo > .ps-push-doc');
        self::assertSame(1, $doc->count());

        $abrir = $doc->filter('.ps-push-doc > a.ps-push-doc-link');
        self::assertSame(self::LINK_PJE, $abrir->attr('href'));
        self::assertSame('_blank', $abrir->attr('target'));
        self::assertStringContainsString('noopener', (string) $abrir->attr('rel'));
        self::assertSame('987654 - Intimação', trim($abrir->text()));

        self::assertSame('987654', $doc->filter('.ps-push-doc > button.js-push-copiar-id')->attr('data-copiar-id'));

        $pje = $doc->filter('.ps-push-doc > a.ps-push-doc-btn[title="Abrir no PJe"]');
        self::assertSame(self::LINK_PJE, $pje->attr('href'));
        self::assertSame('_blank', $pje->attr('target'));
        self::assertStringContainsString('noopener', (string) $pje->attr('rel'));
    }

    #[TestDox('Link que não é http(s) não vira href — é URL de fonte externa')]
    public function testLinkNaoHttpNaoViraHref(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '50000030', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->documentar($pub, '111222', 'javascript:alert(1)');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.ps-push-teor-corpo a[href^="javascript"]')->count());
        self::assertSame(0, $crawler->filter('.ps-push-doc > a')->count(), 'sem link seguro, nada de âncora para o PJe');
        // O ID continua copiável: ele não depende do link.
        self::assertSame('111222', $crawler->filter('.ps-push-doc > .js-push-copiar-id')->attr('data-copiar-id'));
    }

    #[TestDox('Sem ID nem link, a linha do documento não aparece')]
    public function testSemIdNemLinkNaoHaLinhaDoDocumento(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '50000040', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.ps-push-doc')->count());
        self::assertSame(1, $crawler->filter('.ps-push-teor-corpo > .ps-push-acoes')->count(), 'as ações não dependem do documento');
    }

    #[TestDox('Teor: ações "Criar tarefa" e "Marcar como não lida" (abrir já leu), com rota e CSRF')]
    public function testAcoesDoTeor(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '50000050', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->documentar($pub, '555666', self::LINK_PJE);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub->getId()}");

        self::assertResponseIsSuccessful();
        $acoes = $crawler->filter('.ps-push-teor-corpo > .ps-push-acoes');
        self::assertSame(1, $acoes->count());

        $lida = $acoes->filter('.ps-push-acoes > button.js-push-lida');
        self::assertSame("/pasta/{$pasta->getId()}/push/{$pub->getId()}/lida", $lida->attr('data-url'));
        self::assertNotSame('', (string) $lida->attr('data-token'));
        self::assertSame('1', $lida->attr('data-lida'), 'abrir o teor marcou como lida');
        self::assertSame('Marcar como não lida', trim($lida->filter('.js-push-lida-rotulo')->text()));

        $meta = $acoes->filter('.ps-push-acoes > button.js-push-criar-meta');
        self::assertSame('Criar tarefa', trim($meta->text()));
        self::assertSame('Intimação', $meta->attr('data-meta-tipo'));
        self::assertSame('20/08/2026', $meta->attr('data-meta-data'));
        self::assertSame('1ª Vara Cível de Brasília', $meta->attr('data-meta-orgao'));
        self::assertSame('0701134-57.2025.8.07.0007', $meta->attr('data-meta-processo'));
        self::assertSame('555666', $meta->attr('data-meta-documento'));
        self::assertSame(self::LINK_PJE, $meta->attr('data-meta-link'));

        // As notas técnicas e o atalho do módulo continuam no fragmento.
        self::assertSame(1, $crawler->filter('.ps-push-teor-corpo > .ps-notas')->count());
        self::assertSame(1, $crawler->filter('.ps-push-teor-corpo > .ps-push-teor-pe > a[href^="/push-processual/"]')->count());
    }

    #[TestDox('O modal de nova meta que o "Criar tarefa" preenche está na pasta, com os ids que o JS usa')]
    public function testModalDeNovaMetaComOsIdsDoContrato(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#modalCriarTarefa form#formCriarTarefa');
        self::assertSame(1, $form->count(), 'o "Criar tarefa" reusa o modal e a rota de sempre — sem endpoint novo');
        self::assertNotSame('', (string) $form->attr('action'));
        self::assertSame(1, $form->filter('input#tarefaTitulo')->count());
        self::assertSame(1, $form->filter('textarea#tarefaDescricao')->count());
        self::assertSame(1, $form->filter('input#tarefaPrazo[type="date"]')->count());
    }
}
