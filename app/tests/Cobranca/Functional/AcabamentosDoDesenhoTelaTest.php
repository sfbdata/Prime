<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Acabamentos do desenho na Cobrança (inventário de 21/10/2026, itens livres da 0d): o contador e o rodapé da
 * Dívida (Objeto 1A L152 e L227, lista completa, sem "Carregar mais"), as dicas da Nova anotação (L268-283), o
 * selo "Cobrança ativa" só na cobrança em curso (L57) e a área de arrastar do modal da Carteira (Carteira 1B
 * L457-461, com os limites reais do servidor).
 */
#[CoversClass(ObjetoController::class)]
#[CoversClass(CarteiraController::class)]
final class AcabamentosDoDesenhoTelaTest extends CobrancaWebTestCase
{
    #[TestDox('Dívida: o contador ao lado do título e o rodapé "N obrigações em aberto"; a lista vem completa, sem "Carregar mais"')]
    public function testContadorERodapeDaDivida(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        foreach (['Competência 01', 'Competência 02', 'Competência 03'] as $descricao) {
            ObrigacaoFactory::createOne([
                'tenant' => $tenant, 'caso' => $caso, 'descricao' => $descricao,
                'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
                'vencimentoOriginal' => new \DateTimeImmutable('-40 days'),
            ]);
        }

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $secao = $crawler->filter('#secao-divida');
        self::assertSame('3 obrigações', trim($secao->filter('.jp-secao-head > .jp-secao-titulo + .jp-secao-contador')->text()));
        self::assertSame('3 obrigações em aberto', trim($secao->filter('.jp-lista > .jp-lista-rodape:last-child')->text()));
        self::assertCount(3, $secao->filter('.jp-lista > .jp-obr'), 'a lista vem completa');
        self::assertStringNotContainsString('Carregar mais', $secao->text());
    }

    #[TestDox('Nova anotação: o subtítulo ao lado do título e a dica das 48 horas na linha do botão')]
    public function testDicasDaNovaAnotacao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $nova = $crawler->filter('.cob-anotacao-nova');
        self::assertSame('registra no histórico da cobrança', trim($nova->filter('.cob-anotacao-nova-topo > h6 + .cob-anotacao-nova-sub')->text()));
        self::assertSame('Você pode corrigir a própria anotação nas 48 horas seguintes.', trim($nova->filter('.cob-anotacao-nova-rodape > .cob-anotacao-nova-dica')->text()));
        self::assertCount(1, $nova->filter('.cob-anotacao-nova-rodape > .cob-anotacao-nova-dica + button.js-salvar-anotacao'), 'a dica à esquerda do botão');
    }

    #[TestDox('Selo: "Cobrança ativa" com o ponto na cobrança em curso; judicializada continua no selo de sempre')]
    public function testSeloDoEstado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $ativa] = $this->semearGrafo($tenant);
        [, $judicializada] = $this->semearGrafo($tenant);
        $judicializada->setStatus(StatusCaso::Judicializado);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $ativa->getObjeto()->getId());
        self::assertSame('Cobrança ativa', trim($crawler->filter('.cob-cab-identidade .cob-selo-ativa')->text()));

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $judicializada->getObjeto()->getId());
        self::assertCount(0, $crawler->filter('.cob-cab-identidade .cob-selo-ativa'));
        self::assertSame('Judicializado', trim($crawler->filter('.cob-cab-identidade .cob-cab-sobretitulo + .badge')->text()));
    }

    #[TestDox('Carteira: a área "Arraste o arquivo ou clique para escolher" com os limites reais e o input obrigatório dentro dela')]
    public function testAreaDeArrastarDaCarteira(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        self::assertResponseIsSuccessful();

        $area = $crawler->filter('#modalAdicionarDocumentoCarteira label.cs-soltar[data-soltar-arquivo][for="carteiraDocumentoArquivo"]');
        self::assertCount(1, $area);
        self::assertSame('Arraste o arquivo ou clique para escolher', trim($area->filter('.cs-soltar-titulo')->text()));
        self::assertSame('PDF ou planilha até 10 MB · imagem até 3 MB', trim($area->filter('.cs-soltar-dica')->text()));
        self::assertCount(1, $area->filter('input[type="file"][name="arquivo"][required]#carteiraDocumentoArquivo'));
        self::assertCount(1, $crawler->filter('script[src*="js/cobranca-soltar-arquivo.js"]'));
    }
}
