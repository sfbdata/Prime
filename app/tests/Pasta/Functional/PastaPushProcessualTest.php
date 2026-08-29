<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A aba Push Processual da pasta: as publicações captadas do DJEN para os processos DAQUELA pasta.
 *
 * Duas garantias que a suíte precisa segurar e que não são óbvias na leitura do código:
 *  · a publicação AVULSA (sem FK de processo) cujo número casa com o processo da pasta APARECE —
 *    em produção eram 8 publicações nessa situação, todas com o processo cadastrado depois da
 *    captura;
 *  · a aba aparece para quem NÃO tem `modules.djen.view` — decisão do dono: o push é conteúdo do
 *    caso, e quem pode abrir a pasta pode lê-lo.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushProcessualTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const NUMERO_ALHEIO   = '07099999999999999999';

    #[TestDox('A aba lista a publicação do processo vinculado à pasta, com a contagem no badge')]
    public function testListaPublicacaoDoProcessoDaPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '20000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->criarPublicacao($tenant, '20000002', self::NUMERO_DA_PASTA, '2026-08-28', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#pastaTabs > #push-tab')->count(), 'a aba precisa ser filha direta da faixa de abas');
        self::assertSame(2, $crawler->filter('.ps-push-lista > .ps-push-item')->count());
        self::assertSame('2', trim($crawler->filter('#push-tab .ps-aba-badge')->text()));
    }

    #[TestDox('Arranjo: a aba é a última da faixa e o painel dela é filho direto do conteúdo das abas')]
    public function testArranjoDaAbaEDoPainel(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $abas = $crawler->filter('#pastaTabs > .ps-aba');
        self::assertSame(7, $abas->count(), 'a faixa passa a ter sete abas');
        self::assertSame('push-tab', $abas->last()->attr('id'), 'a aba nova entra no fim, sem mexer na posição das seis que já existiam');

        // Filho DIRETO: "existe na página" não distingue painel no lugar certo de painel solto.
        self::assertSame(1, $crawler->filter('#pastaTabsContent > #push')->count());
        self::assertSame(1, $crawler->filter('#push > .ps-push')->count());
    }

    #[TestDox('Publicação AVULSA cujo número casa com o processo da pasta aparece — o caso dos 8 de produção')]
    public function testAvulsaComNumeroDoProcessoAparece(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $avulsa = $this->criarPublicacao($tenant, '20000010', self::NUMERO_DA_PASTA, '2026-07-21');
        self::assertNull($avulsa->getProcesso(), 'o teste só vale se a publicação estiver mesmo sem FK');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame(1, $crawler->filter('.ps-push-item[data-push-id="' . $avulsa->getId() . '"]')->count());
    }

    #[TestDox('A pasta não mostra publicação de processo de OUTRA pasta do mesmo escritório')]
    public function testNaoMostraPublicacaoDePastaIrma(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pastaA          = $this->criarPasta($tenant);
        $pastaB          = $this->criarPasta($tenant);
        $this->vincular($pastaA, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));
        $processoB = $this->criarProcesso($tenant, self::NUMERO_ALHEIO);
        $this->vincular($pastaB, $processoB);
        $daIrma = $this->criarPublicacao($tenant, '20000020', self::NUMERO_ALHEIO, '2026-08-20', $processoB);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pastaA->getId()}");

        self::assertSame(0, $crawler->filter('.ps-push-item[data-push-id="' . $daIrma->getId() . '"]')->count());
        self::assertSame(0, $crawler->filter('.ps-push-lista > .ps-push-item')->count());
    }

    #[TestDox('Pasta sem processo vinculado explica que é isso que falta')]
    public function testEstadoVazioSemProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame(1, $crawler->filter('#push-vazio-sem-processo')->count());
        self::assertSame(0, $crawler->filter('#push-vazio-sem-publicacao')->count());
    }

    #[TestDox('Pasta com processo e sem publicação recebe o outro texto, não o mesmo')]
    public function testEstadoVazioComProcessoSemPublicacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame(1, $crawler->filter('#push-vazio-sem-publicacao')->count());
        self::assertSame(0, $crawler->filter('#push-vazio-sem-processo')->count());
    }

    #[TestDox('Quem não tem a permissão do módulo Push Processual continua vendo a aba na pasta')]
    public function testAbaVisivelSemPermissaoDoModulo(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $user     = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '20000030', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#pastaTabs > #push-tab')->count());
        self::assertSame(1, $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"]')->count());
    }
}
