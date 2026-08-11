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
 * A lista de objetos cobrados da carteira (`cobranca_carteira_show`) precisa ser navegável sem
 * mouse.
 *
 * A linha inteira é clicável por um listener de `click` em `show.html.twig` — conveniência de
 * mouse, e só. Listener de clique não recebe foco, não entra na ordem de tabulação e não responde
 * ao Enter: enquanto o destino existia apenas no `data-href` da `<tr>`, quem navega por teclado ou
 * leitor de tela não conseguia abrir cobrança nenhuma a partir desta tela.
 *
 * O destino de verdade passou a ser um `<a href>` no identificador do objeto. Estes testes fixam o
 * que não pode regredir: que o link existe em toda linha, e que ele aponta para o MESMO lugar que
 * o clique na linha — se os dois divergirem, o mouse e o teclado levam o usuário a cobranças
 * diferentes, que é pior do que não ter link.
 */
#[CoversClass(CarteiraController::class)]
final class ListaCarteiraAcessivelTest extends CobrancaWebTestCase
{
    /** @param array<int, array{0: string, 1: string}> $casos pares [identificação, pessoa cobrada] */
    private function carteiraCom(Tenant $tenant, array $casos): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();

        foreach ($casos as [$identificacao, $nomePessoa]) {
            $objeto = ObjetoCobrancaFactory::createOne([
                'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => $identificacao,
            ])->_real();

            CasoCobrancaFactory::createOne([
                'tenant' => $tenant,
                'objeto' => $objeto,
                'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nomePessoa]),
            ]);
        }

        return $carteira;
    }

    #[TestDox('Cada objeto cobrado da lista traz um link de verdade, alcançável pelo teclado')]
    public function testCadaLinhaTrazUmLinkAlcancavelPeloTeclado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraCom($tenant, [
            ['CONTRATO-7001', 'Mariana Albuquerque'],
            ['CONTRATO-8002', 'Roberto Cavalcanti'],
        ]);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        $linhas = $crawler->filter('[data-filtro-resultado] tbody tr');
        self::assertSame(2, $linhas->count(), 'A carteira tinha de listar os dois objetos cobrados');

        $links = $crawler->filter('[data-filtro-resultado] tbody tr a[href^="/cobrancas/objetos/"]');
        self::assertSame(
            $linhas->count(),
            $links->count(),
            'Toda linha precisa de um <a href> — sem ele o destino só existe no listener de clique, que o teclado não alcança',
        );

        // O link tem de carregar o identificador do objeto: é o texto que o leitor de tela anuncia.
        // Um link vazio (ou com "ver"/"abrir") passaria na contagem acima e não diria nada a ninguém.
        $rotulos = $links->each(static fn ($no): string => trim($no->text()));
        sort($rotulos);
        self::assertSame(['CONTRATO-7001', 'CONTRATO-8002'], $rotulos);
    }

    #[TestDox('O link do teclado e o clique na linha levam ao mesmo objeto cobrado')]
    public function testLinkECliqueNaLinhaApontamParaOMesmoDestino(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteiraCom($tenant, [['CONTRATO-9003', 'Helena Peçanha']]);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();

        $linha = $crawler->filter('[data-filtro-resultado] tbody tr')->first();
        $destinoDoMouse = $linha->attr('data-href');
        $destinoDoTeclado = $linha->filter('a[href^="/cobrancas/objetos/"]')->first()->attr('href');

        self::assertNotEmpty($destinoDoMouse, 'A linha perdeu o data-href que o listener de clique usa');
        self::assertSame(
            $destinoDoMouse,
            $destinoDoTeclado,
            'Mouse e teclado têm de abrir a MESMA cobrança; destinos divergentes enganam quem navega sem mouse',
        );
    }
}
