<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Busca livre da página da carteira (`cobranca_carteira_show`): um único campo, sem facetas, que
 * casa por identificação/descrição do OBJETO e por nome da PESSOA COBRADA — mesmo componente do
 * Expediente (`_partials/_filtro_barra.html.twig` + `public/js/filtro-tabela.js`).
 *
 * Prova o que a camada HTTP tem de garantir: a barra existe na página cheia, o XHR devolve SÓ o
 * fragmento da lista (contrato do motor JS), o filtro esconde quem não casa sem mexer no cabeçalho,
 * e a busca não atravessa tenant (o gate de leitura é anterior, mas o filtro não pode ser a porta
 * dos fundos).
 */
#[CoversClass(CarteiraController::class)]
final class BuscaCarteiraShowTest extends CobrancaWebTestCase
{
    /** Carteira com dois casos de identificação/pessoa conhecidas, no tenant informado. */
    private function carteiraComDoisCasos(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();

        $this->caso($tenant, $carteira, 'CONTRATO-7001', 'Mariana Albuquerque');
        $this->caso($tenant, $carteira, 'CONTRATO-8002', 'Roberto Cavalcanti');

        return $carteira;
    }

    private function caso(Tenant $tenant, Carteira $carteira, string $identificacao, string $nomePessoa): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => $identificacao,
        ])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nomePessoa]),
        ])->_real();
    }

    #[TestDox('Página cheia traz a barra de busca ligada ao endpoint da própria carteira')]
    public function testPaginaCheiaTrazABarraDeBusca(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisCasos($tenant);
        $url = '/cobrancas/carteiras/' . $carteira->getId();

        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-filtro-root][data-filtro-endpoint="' . $url . '"]')->count());
        self::assertSame(1, $crawler->filter('[data-filtro-root] [data-filtro-resultado]')->count());
        self::assertSame(1, $crawler->filter('input.js-filtro-busca[name="busca"]')->count());
        // Sem facetas: só a busca livre, como pedido.
        self::assertSame(0, $crawler->filter('[data-filtro-root] select.js-filtro-campo')->count());
    }

    #[TestDox('Busca por nome da pessoa cobrada esconde quem não casa e mantém quem casa')]
    public function testBuscaPorPessoaFiltraALista(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisCasos($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '?busca=albuquerque');

        self::assertResponseIsSuccessful();
        $lista = $crawler->filter('[data-filtro-resultado]')->text();
        self::assertStringContainsString('Mariana Albuquerque', $lista);
        self::assertStringNotContainsString('Roberto Cavalcanti', $lista);
        self::assertSame(1, $crawler->filter('[data-filtro-resultado] tbody tr')->count());
    }

    #[TestDox('Busca por identificação do objeto filtra a lista')]
    public function testBuscaPorIdentificacaoFiltraALista(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisCasos($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '?busca=8002');

        self::assertResponseIsSuccessful();
        $lista = $crawler->filter('[data-filtro-resultado]')->text();
        self::assertStringContainsString('CONTRATO-8002', $lista);
        self::assertStringNotContainsString('CONTRATO-7001', $lista);
    }

    #[TestDox('XHR devolve SÓ o fragmento da lista (contrato do filtro-tabela.js)')]
    public function testXhrDevolveSoOFragmento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisCasos($tenant);

        $client->request(
            'GET',
            '/cobrancas/carteiras/' . $carteira->getId() . '?busca=albuquerque',
            [],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('<html', $html, 'no XHR não pode vir a página inteira');
        self::assertStringNotContainsString('data-filtro-resultado', $html, 'o fragmento é o CONTEÚDO da área trocável');
        self::assertStringNotContainsString('Documentos', $html, 'o card de documentos fica fora do fragmento');
        self::assertStringNotContainsString('Novo objeto', $html, 'o botão permissionado não viaja no fragmento');
        self::assertStringContainsString('Mariana Albuquerque', $html);
        self::assertStringNotContainsString('Roberto Cavalcanti', $html);
    }

    #[TestDox('Busca sem resultado mostra o vazio da BUSCA, não o da carteira vazia')]
    public function testBuscaSemResultadoTemVazioProprio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisCasos($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '?busca=zzz-nao-existe');

        self::assertResponseIsSuccessful();
        $lista = $crawler->filter('[data-filtro-resultado]')->text();
        self::assertStringContainsString('Nenhuma cobrança encontrada', $lista);
        self::assertStringNotContainsString('Nenhuma cobrança nesta carteira', $lista);
        self::assertSame(0, $crawler->filter('[data-filtro-resultado] tbody tr')->count());
    }

    #[TestDox('Busca não atravessa tenant: termo que casa em outro escritório não traz nada')]
    public function testBuscaNaoAtravessaTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisCasos($tenant);

        // Grafo de OUTRO escritório com um termo bem distinto.
        $outro = $this->tenantAvulso();
        $carteiraAlheia = CarteiraFactory::createOne([
            'tenant' => $outro,
            'cliente' => ClientePFFactory::createOne(['tenant' => $outro]),
        ])->_real();
        $this->caso($outro, $carteiraAlheia, 'CONTRATO-SEGREDO', 'Devedor Alheio');

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '?busca=SEGREDO');

        self::assertResponseIsSuccessful();
        $lista = $crawler->filter('[data-filtro-resultado]')->text();
        self::assertStringNotContainsString('Devedor Alheio', $lista);
        self::assertSame(0, $crawler->filter('[data-filtro-resultado] tbody tr')->count());
    }
}
