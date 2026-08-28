<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Arranjo da página da carteira: lista à ESQUERDA, trilho de Configuração e Documentos à DIREITA
 * (faixa fixa de 340px), lado a lado na MESMA grade.
 *
 * A grade deixou de ser `.row`/`.col-lg-*` no redesenho 1B: o desenho pede
 * `minmax(0,1fr) 340px`, medida que as 12 colunas do Bootstrap não sabem expressar. O que este
 * teste guarda não mudou — é a ESTRUTURA, não os nomes das classes.
 *
 * Este teste existe por causa de um defeito que passou por tudo. Ao trocar o arranjo, sobrou um
 * `</div>` que fechava a `.row` antes do trilho: o Bootstrap então desenhava o `col-lg-4` como
 * bloco solto abaixo da lista, em vez de ao lado dela. A tela ficou visivelmente errada e mesmo
 * assim **3459 testes passaram e o `lint:twig` deu OK** — porque lint valida sintaxe Twig, não
 * aninhamento de HTML, e nenhuma asserção olhava a ESTRUTURA da página.
 *
 * Por isso as asserções abaixo usam o combinador de filho direto (`>`): é ele que distingue "estão
 * os dois na mesma linha do grid" de "existem os dois em algum lugar da página" — que era verdade
 * mesmo com o layout quebrado.
 */
#[CoversClass(CarteiraController::class)]
final class CarteiraArranjoTelaTest extends CobrancaWebTestCase
{
    #[TestDox('Lista e trilho sao colunas irmas da MESMA linha do grid')]
    public function testListaETrilhoDividemALinhaDoGrid(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => 'UNIDADE 42',
        ])->_real();
        CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Ivete Sangalo']),
        ]);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        $grade = $crawler->filter('.cobrancas-page .cs-grade');
        self::assertGreaterThan(0, $grade->count(), 'Sumiu a grade que divide lista e trilho');

        self::assertSame(
            1,
            $crawler->filter('.cobrancas-page .cs-grade > .cs-coluna-lista [data-filtro-resultado]')->count(),
            'A lista de objetos cobrados tem de estar dentro da coluna flexível da grade',
        );
        self::assertSame(
            1,
            $crawler->filter('.cobrancas-page .cs-grade > .cs-trilho')->count(),
            'O trilho tem de ser filho DIRETO da grade — solto fora dela ele cai para baixo da lista, '
            . 'que foi o defeito original',
        );
    }

    #[TestDox('Configuracao e Documentos moram dentro do trilho, nao soltos na pagina')]
    public function testConfiguracaoEDocumentosEstaoNoTrilho(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        // Dois cartões dentro do trilho: Configuração e Documentos. Se um deles escapar para fora,
        // volta a aparecer em largura inteira embaixo da página — o arranjo que o dono recusou.
        self::assertSame(
            2,
            $crawler->filter('.cs-trilho > .cs-card')->count(),
            'O trilho tem de conter exatamente os dois cartoes: Configuracao e Documentos',
        );

        $trilho = $crawler->filter('.cs-trilho')->text();
        self::assertStringContainsString('Configuração', $trilho);
        self::assertStringContainsString('Documentos', $trilho);
    }
}
