<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote P4 da aba Push (desenho 1.2.3): filtro "Geram prazo" pela regra do desenho sobre o tipo
 * (item 9), pílula do dia "5 out 2026" (N1) e canto do cartão sem a data repetida (N2).
 *
 * Arranjo com combinador de FILHO DIRETO. O JS esconder as linhas não é visível aqui — fica para
 * o smoke na tela; o que se prova é a marca que ele lê.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushGeramPrazoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';

    #[TestDox('Item 9: Intimação e Decisão são marcadas para "Geram prazo"; Edital e Lista de distribuição não')]
    public function testMarcaGeraPrazoPeloTipo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);

        $esperado = [
            'Intimação'             => '1',
            'Decisão'               => '1',
            'Edital'                => '0',
            'Lista de distribuição' => '0',
        ];
        $ids = [];
        $n   = 0;
        foreach ($esperado as $tipo => $marca) {
            $pub = $this->criarPublicacao($tenant, (string) (74000000 + ++$n), self::NUMERO_DA_PASTA, '2026-08-20', $processo);
            $pub->setTipoComunicacao($tipo);
            $ids[$pub->getId()] = [$tipo, $marca];
        }
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        foreach ($ids as $id => [$tipo, $marca]) {
            $li = $crawler->filter(sprintf('#push > .ps-push > .ps-push-lista > .ps-push-item[data-push-id="%s"]', $id));
            self::assertCount(1, $li, $tipo);
            self::assertSame($marca, $li->attr('data-push-gera-prazo'), $tipo);
        }
        self::assertCount(2, $crawler->filter('.ps-push-lista > .ps-push-item[data-push-gera-prazo="1"]'));
    }

    #[TestDox('Item 9: nenhuma publicação gera prazo — o filtro existe e o vazio do filtro também (todas marcadas 0)')]
    public function testNenhumaGeraPrazo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '74100001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTipoComunicacao('Edital');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#push > .ps-push > .ps-card-cab > .ps-push-filtros > .ps-push-filtro[data-push-filtro="prazo"]'));
        self::assertCount(0, $crawler->filter('.ps-push-lista > .ps-push-item[data-push-gera-prazo="1"]'));
        self::assertCount(1, $crawler->filter('#push > .ps-push > #push-vazio-filtro[hidden]'));
    }

    #[TestDox('Item 9 + isolamento: Intimação de OUTRO escritório no mesmo número não entra na lista nem na marca')]
    public function testIntimacaoDeOutroEscritorioNaoAparece(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $propria = $this->criarPublicacao($tenant, '74200001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $propria->setTipoComunicacao('Edital');

        // Mesmo número de processo, Intimação (que geraria prazo), mas de outro escritório.
        $alheio     = $this->criarTenant();
        $pubAlheia = $this->criarPublicacao($alheio, '74200002', self::NUMERO_DA_PASTA, '2026-08-21');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('.ps-push-lista > .ps-push-item'));
        self::assertCount(0, $crawler->filter(sprintf('.ps-push-item[data-push-id="%s"]', $pubAlheia->getId())));
        self::assertCount(0, $crawler->filter('.ps-push-lista > .ps-push-item[data-push-gera-prazo="1"]'));
        self::assertSame('1', trim($crawler->filter('#push > .ps-push > .ps-card-cab > .ps-contagem')->text()));
    }

    #[TestDox('N1: a pílula do dia diz "5 out 2026"; data-push-dia segue dd/mm/aaaa para o JS')]
    public function testPilulaDoDiaPorExtenso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '74300001', self::NUMERO_DA_PASTA, '2026-10-05', $processo);
        $this->criarPublicacao($tenant, '74300002', self::NUMERO_DA_PASTA, '2026-12-25', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $pilulas = $crawler->filter('.ps-push-lista > .ps-push-item > .ps-push-dia > .ps-push-dia-pilula')
            ->each(static fn ($p) => trim($p->text()));
        sort($pilulas);
        self::assertSame(['25 dez 2026', '5 out 2026'], $pilulas);

        $dias = $crawler->filter('.ps-push-lista > .ps-push-item')->each(static fn ($li) => (string) $li->attr('data-push-dia'));
        sort($dias);
        self::assertSame(['05/10/2026', '25/12/2026'], $dias);
    }

    #[TestDox('N2: o canto do cartão não repete a data da pílula (o DJEN não dá hora)')]
    public function testCantoDoCartaoSemDataRepetida(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '74400001', self::NUMERO_DA_PASTA, '2026-10-05', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $corpo = $crawler->filter('.ps-push-lista > .ps-push-item > .ps-push-cartao > .ps-push-cab > .ps-push-corpo');
        self::assertCount(1, $corpo);
        self::assertCount(0, $corpo->filter('.ps-push-hora'));
        self::assertStringNotContainsString('05/10/2026', $corpo->text());
        // A seta do acordeão continua filha direta do corpo.
        self::assertCount(1, $crawler->filter('.ps-push-cab > .ps-push-corpo > .ps-push-seta'));
    }
}
