<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\Carteira;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Cabeçalho da carteira na cópia de 19/10 do desenho "Carteira 1B": o selo "CARTEIRA {id}" (L91), o
 * rodapé do popover de frescor que diz QUAL relatório importar (L118) e o ⋮ com "Editar configuração"
 * e "Exportar lista (.xlsx)" (L75-84). Os outros dois itens do ⋮ (espelho contábil, excluir carteira)
 * não entram: decisão do dono, sem rota no sistema.
 */
#[CoversClass(CarteiraController::class)]
final class CarteiraCabecalhoDesenho1910Test extends CobrancaWebTestCase
{
    private function carteira(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => 'UNIDADE 101',
        ])->_real();
        CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
        ]);

        return $carteira;
    }

    #[TestDox('O selo do topo diz CARTEIRA com o número dela, antes das pílulas')]
    public function testSeloDoTopoTrazONumeroDaCarteira(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $kicker = $crawler->filter('.content-header .cs-cabecalho > .cs-cab-corpo > .cs-cab-selos > .cs-kicker');
        self::assertCount(1, $kicker);
        // Caixa-alta é do CSS (`text-transform`); no HTML fica o texto natural.
        self::assertSame('Carteira ' . $carteira->getId(), trim($kicker->text()));
        self::assertCount(1, $crawler->filter('.cs-cab-selos > .cs-kicker:first-child'), 'O selo abre a faixa, como no desenho');
    }

    #[TestDox('O rodapé do popover de frescor manda importar o relatório da emissão mais antiga')]
    public function testRodapeDoFrescorApontaORelatorioMaisAntigo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);
        $carteira->registrarEmissaoImportada('inadimplencia', new \DateTimeImmutable('-2 days'));
        $carteira->registrarEmissaoImportada('cadastro', new \DateTimeImmutable('-15 days'));
        $carteira->registrarEmissaoImportada('receitas', new \DateTimeImmutable('-5 days'));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $nota = $crawler->filter('.content-header .cs-frescor-menu > .cs-frescor-nota');
        self::assertCount(1, $nota);
        self::assertSame(
            'A carteira só está em dia até a emissão mais antiga. Importe o cadastro para fechar a defasagem.',
            (string) preg_replace('/\s+/u', ' ', trim($nota->text())),
        );
        self::assertSame('cadastro', $nota->filter('[data-frescor-importar]')->attr('data-frescor-importar'));
    }

    #[TestDox('Com outro relatório como o mais antigo, a frase troca de relatório')]
    public function testRodapeDoFrescorAcompanhaOTipo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);
        $carteira->registrarEmissaoImportada('inadimplencia', new \DateTimeImmutable('-9 days'));
        $carteira->registrarEmissaoImportada('receitas', new \DateTimeImmutable('-2 days'));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $nota = $crawler->filter('.content-header .cs-frescor-menu > .cs-frescor-nota');
        self::assertStringContainsString(
            'Importe a inadimplência para fechar a defasagem.',
            (string) preg_replace('/\s+/u', ' ', $nota->text()),
        );
    }

    #[TestDox('Empate na emissão mais antiga fica com o primeiro na ordem da lista')]
    public function testEmpateFicaComOPrimeiroDaOrdem(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);
        $mesmaEmissao = new \DateTimeImmutable('-6 days');
        // Registrados fora da ordem da lista, de propósito: quem desempata é a ordem, não o registro.
        $carteira->registrarEmissaoImportada('cadastro', $mesmaEmissao);
        $carteira->registrarEmissaoImportada('acordos', $mesmaEmissao);
        $carteira->registrarEmissaoImportada('receitas', new \DateTimeImmutable('-1 day'));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $nota = $crawler->filter('.content-header .cs-frescor-menu > .cs-frescor-nota');
        self::assertSame('acordos', $nota->filter('[data-frescor-importar]')->attr('data-frescor-importar'));
        self::assertStringContainsString(
            'Importe os acordos detalhados para fechar a defasagem.',
            (string) preg_replace('/\s+/u', ' ', $nota->text()),
        );
    }

    #[TestDox('Tipo de relatório desconhecido usa o mesmo rótulo da lista do popover')]
    public function testTipoDesconhecidoUsaORotuloDaLista(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);
        $carteira->registrarEmissaoImportada('boletos', new \DateTimeImmutable('-20 days'));
        $carteira->registrarEmissaoImportada('inadimplencia', new \DateTimeImmutable('-3 days'));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('.content-header .cs-frescor-menu');
        // A lista já mostra o tipo novo como "Boletos"; o rodapé fala a mesma língua.
        self::assertContains('Boletos', $menu->filter('.cs-frescor-item > span:first-child')->each(static fn ($n): string => trim($n->text())));
        $nota = $menu->filter(':scope > .cs-frescor-nota');
        self::assertSame('boletos', $nota->filter('[data-frescor-importar]')->attr('data-frescor-importar'));
        self::assertStringContainsString(
            'Importe o relatório de Boletos para fechar a defasagem.',
            (string) preg_replace('/\s+/u', ' ', $nota->text()),
        );
    }

    #[TestDox('O ⋮ do cabeçalho tem "Editar configuração" e "Exportar lista (.xlsx)", nessa ordem')]
    public function testMenuDoCabecalhoAbreAConfiguracaoEExporta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $mais = $crawler->filter('.content-header .cs-cabecalho > .cs-cab-topo > .cs-cab-acoes > .cs-mais');
        self::assertCount(1, $mais, 'O ⋮ mora nas ações do topo do cartão');
        self::assertCount(1, $crawler->filter('.cs-cab-acoes > .cs-mais:last-child'), 'O ⋮ fecha a fila de ações, como no desenho');

        $botao = $mais->filter(':scope > button[data-bs-toggle="dropdown"]');
        self::assertCount(1, $botao);
        self::assertCount(1, $botao->filter('i.bi-three-dots-vertical'));
        self::assertSame('Mais ações', $botao->attr('title'));

        $itens = $mais->filter(':scope > .cs-mais-menu > *');
        self::assertCount(2, $itens, 'Só os itens que têm ação no sistema');

        $config = $itens->eq(0);
        self::assertSame('Editar configuração', trim($config->text()));
        self::assertSame('modal', $config->attr('data-bs-toggle'));
        self::assertSame('#modalEditarConfiguracao', $config->attr('data-bs-target'));
        self::assertCount(1, $config->filter('i.bi-sliders'));
        self::assertCount(1, $crawler->filter('#modalEditarConfiguracao'));

        $exportar = $itens->eq(1);
        self::assertSame('a', $exportar->nodeName());
        self::assertSame('Exportar lista (.xlsx)', trim($exportar->text()));
        self::assertCount(1, $exportar->filter('i.bi-file-earmark-spreadsheet'));
        self::assertSame('/cobrancas/carteiras/' . $carteira->getId() . '/exportar', $exportar->attr('data-exportar-base'));

        $texto = $mais->text();
        foreach (['espelho', 'Excluir carteira'] as $fora) {
            self::assertStringNotContainsString($fora, $texto);
        }
    }

    #[TestDox('O link de exportar sai com a busca e o Estado da carga da página')]
    public function testExportarLevaORecorteDaTela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '?busca=101&estado=ativo');

        self::assertResponseIsSuccessful();
        $href = (string) $crawler->filter('.cs-mais-menu > a[data-exportar-lista]')->attr('href');
        self::assertStringStartsWith('/cobrancas/carteiras/' . $carteira->getId() . '/exportar?', $href);
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame('101', $query['busca'] ?? null);
        self::assertSame('ativo', $query['estado'] ?? null);
        self::assertArrayNotHasKey('page', $query, 'A planilha não pagina');

        // O recorte muda por XHR sem recarregar: o script refaz o href também no clique do meio e no
        // menu de contexto ("copiar link"), além da troca do formulário e do fragmento.
        $html = (string) $client->getResponse()->getContent();
        foreach (["'auxclick'", "'contextmenu'", "'change'", 'MutationObserver'] as $gancho) {
            self::assertStringContainsString($gancho, $html);
        }
    }

    #[TestDox('Sem gerenciar carteira, o ⋮ fica só com o exportar: nunca aponta para um modal que não existe')]
    public function testSemGerenciarCarteiraOMenuSoExporta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        $carteira = $this->carteira($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        $itens = $crawler->filter('.cs-cab-acoes > .cs-mais > .cs-mais-menu > *');
        self::assertCount(1, $itens);
        self::assertSame('Exportar lista (.xlsx)', trim($itens->text()));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalEditarConfiguracao"]'));
        self::assertCount(0, $crawler->filter('#modalEditarConfiguracao'));
    }
}
