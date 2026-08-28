<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O que o redesenho 1B promete NA TELA da carteira (`cobranca_carteira_show`), além do arranjo já
 * trancado pelo `CarteiraArranjoTelaTest`.
 *
 * Este arquivo existe porque suíte verde é CEGA para aparência: em 10/08 três mil e tantos testes
 * passaram com o layout visivelmente quebrado. Então aqui só entra o que é estrutural e verificável
 * em HTML — quantidade e ordem de blocos, o recorte que o filtro faz, o que o vazio diz. Borda, cor
 * e tamanho de fonte continuam sendo trabalho do smoke no navegador, e não se finge o contrário.
 */
#[CoversClass(CarteiraController::class)]
final class CarteiraRedesenho1BTest extends CobrancaWebTestCase
{
    /** Carteira com um caso ATIVO e um JUDICIALIZADO, para o filtro ter o que separar. */
    private function carteiraComDoisEstados(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();

        foreach ([['UNIDADE 101', 'Ana Ativa', StatusCaso::Ativo], ['UNIDADE 202', 'Bruno Judicial', StatusCaso::Judicializado]] as [$id, $nome, $status]) {
            $objeto = ObjetoCobrancaFactory::createOne([
                'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => $id,
            ])->_real();
            CasoCobrancaFactory::createOne([
                'tenant' => $tenant,
                'objeto' => $objeto,
                'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nome]),
                'status' => $status,
            ]);
        }

        return $carteira;
    }

    #[TestDox('O cabecalho traz os QUATRO indicadores do desenho, nessa ordem')]
    public function testCabecalhoTrazOsQuatroIndicadores(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisEstados($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        // Filho DIRETO: um indicador que escape da faixa vira bloco solto no cabeçalho, e é
        // exatamente esse tipo de escape que passa por lint e por asserção de texto.
        $rotulos = $crawler->filter('.content-header .cs-kpis > .cs-kpi .cs-kpi-rotulo')->each(
            static fn ($no): string => trim($no->text()),
        );

        self::assertSame(['Saldo consolidado', 'Vencido', 'Objetos cobrados', 'Casos abertos'], $rotulos);

        // A sub-linha do primeiro indicador é a frase que evita o chamado "o total não bate com a
        // lista": busca e filtro recortam a LISTA, nunca os agregados.
        self::assertStringContainsString(
            'carteira inteira, não a página',
            $crawler->filter('.cs-kpis > .cs-kpi')->first()->text(),
        );
    }

    #[TestDox('O selo de frescor detalha as emissoes e marca a MAIS ANTIGA')]
    public function testSeloDeFrescorMarcaAEmissaoMaisAntiga(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisEstados($tenant);

        $carteira->registrarEmissaoImportada('inadimplencia', new \DateTimeImmutable('-9 days'));
        $carteira->registrarEmissaoImportada('receitas', new \DateTimeImmutable('-2 days'));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        // O detalhamento continua DENTRO do .content-header, que é onde o
        // CarteiraDadosAtualizadosNaTelaTest procura o selo.
        $menu = $crawler->filter('.content-header .cs-frescor-menu');
        self::assertSame(1, $menu->count(), 'Sumiu o detalhamento por relatório do selo de frescor');
        self::assertSame(2, $menu->filter('.cs-frescor-item')->count(), 'Uma linha por tipo de relatório importado');

        // Uma só em vermelho, e é a mais antiga — é ela que define a data do selo. Marcar as duas (ou
        // nenhuma) desmontaria a explicação que o rodapé do popover dá.
        $destacadas = $menu->filter('.cs-frescor-data.is-mais-antiga');
        self::assertSame(1, $destacadas->count());
        self::assertSame((new \DateTimeImmutable('-9 days'))->format('d/m/Y'), trim($destacadas->text()));
    }

    #[TestDox('Filtrar por Estado recorta a LISTA e nao mexe no cabecalho')]
    public function testFiltroDeEstadoRecortaSoALista(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisEstados($tenant);
        $url = '/cobrancas/carteiras/' . $carteira->getId();

        $inteira = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $inteira->filter('[data-filtro-resultado] tbody tr')->count());

        $recorte = $client->request('GET', $url . '?estado=judicializado');

        self::assertResponseIsSuccessful();
        $lista = $recorte->filter('[data-filtro-resultado]');
        self::assertSame(1, $lista->filter('tbody tr')->count(), 'O filtro tinha de deixar só o caso judicializado');
        self::assertStringContainsString('Bruno Judicial', $lista->text());
        self::assertStringNotContainsString('Ana Ativa', $lista->text());

        // O cabeçalho responde "quanto esta carteira tem a receber". Escolher um recorte na lista não
        // pode mudar essa resposta — o defeito seria invisível: um número menor, com cara de certo.
        self::assertSame(
            trim($inteira->filter('.cs-kpis > .cs-kpi')->last()->text()),
            trim($recorte->filter('.cs-kpis > .cs-kpi')->last()->text()),
            'O indicador de casos abertos mudou por causa de um filtro da lista',
        );
        self::assertSame(
            '2',
            trim($recorte->filter('.cs-kpis > .cs-kpi')->last()->filter('.cs-kpi-valor')->text()),
            'Casos abertos conta a carteira inteira, nao o recorte da lista',
        );

        // A contagem da lista, essa sim, diz que está recortada.
        self::assertStringContainsString('1 de 2 casos', $recorte->filter('.cs-contagem')->text());
    }

    #[TestDox('Recorte sem resultado tem vazio proprio, com o caminho de volta')]
    public function testRecorteSemResultadoTemVazioProprio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisEstados($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '?estado=encerrado');

        self::assertResponseIsSuccessful();
        $lista = $crawler->filter('[data-filtro-resultado]');

        self::assertSame(0, $lista->filter('tbody tr')->count());
        self::assertStringContainsString('Nenhum caso neste recorte', $lista->text());
        self::assertStringContainsString('Limpar filtro', $lista->text());
        // Sem linha não há o que paginar: "0 de 0" ao lado de um paginador é ruído.
        self::assertSame(0, $lista->filter('.cs-rodape')->count());
    }

    #[TestDox('Estado desconhecido na URL nao esvazia a tela')]
    public function testEstadoDesconhecidoNaoEsvaziaATela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisEstados($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '?estado=pronto-para-encerrar');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('[data-filtro-resultado] tbody tr')->count());
    }

    #[TestDox('So Unidade, Pessoa e Saldo ordenam — Estado nao ganha seta')]
    public function testApenasTresColunasOrdenam(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraComDoisEstados($tenant);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        // O UseCase só aceita `saldo`, `objeto` e `pessoa`; seta em "Estado" prometeria uma ordenação
        // que cai no padrão em silêncio.
        $ordenaveis = $crawler->filter('[data-filtro-resultado] thead th[data-ordenar]')->each(
            static fn ($no): string => (string) $no->attr('data-ordenar'),
        );
        self::assertSame(['objeto', 'pessoa', 'saldo'], $ordenaveis);
        self::assertSame(0, $crawler->filter('[data-filtro-resultado] thead th.cs-col-estado[data-ordenar]')->count());
    }

    #[TestDox('O trilho lista as SETE linhas de configuracao, mesmo com campo vazio')]
    public function testTrilhoMostraAsSeteLinhasDeConfiguracao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Sem vínculo preferido nem rótulo: as duas linhas continuam, com "—".
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant, 'cliente' => $cliente,
            'tipoVinculoPreferido' => null, 'rotuloObjeto' => null,
        ])->_real();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        $rotulos = $crawler->filter('.cs-trilho .cs-config > .cs-config-linha > dt')->each(
            static fn ($no): string => trim($no->text()),
        );

        self::assertSame(
            ['Modo', 'Honorários', 'Tolerância de atraso', 'Juros', 'Multa', 'Vínculo preferido', 'Rótulo do objeto'],
            $rotulos,
            'Linha que some conforme o dado faz o trilho mudar de altura entre carteiras — e some junto '
            . 'com a pergunta que ela responde',
        );
    }
}
