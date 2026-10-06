<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaPushProcessualController;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Twig\NotaTecnicaExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As notas técnicas NA TELA da pasta: o bloco sob o cartão do processo na aba Processo (desenho
 * 1.2.3), a mesma nota em toda pasta que vincula o processo, a janela de 15 min e o teor do
 * Push com só as notas daquela movimentação.
 *
 * Arranjo com combinador de FILHO DIRETO: "está sob o cartão, dentro da lista" é diferente de
 * "existe em algum lugar da página".
 */
#[CoversClass(NotaTecnicaExtension::class)]
#[CoversClass(PastaPushProcessualController::class)]
#[Group('pasta')]
final class PastaNotasTecnicasTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';

    private function criarNota(
        Processo $processo,
        User $autor,
        Tenant $tenant,
        string $conteudo = '<p>Prazo de 15 dias para contestar.</p>',
        ?PublicacaoDjen $pub = null,
        ?string $quando = null,
    ): NotaTecnica {
        $nota = new NotaTecnica($tenant, $processo, $autor, $conteudo, $pub);
        if ($quando !== null) {
            (new \ReflectionProperty(NotaTecnica::class, 'criadaEm'))->setValue($nota, new \DateTimeImmutable($quando));
        }
        $this->em()->persist($nota);
        $this->em()->flush();

        return $nota;
    }

    #[TestDox('na aba Processo, o bloco de notas fica logo abaixo do cartão do processo, dentro da lista')]
    public function testBlocoSobOCartaoDoProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $nota = $this->criarNota($processo, $user, $tenant);
        $pid  = $processo->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // O bloco é filho direto da lista da aba Processo, irmão imediato do cartão do processo.
        self::assertCount(1, $crawler->filter("#processo .ps-processos > .ps-processos-lista > .ps-notas#notas-processo-{$pid}"));
        self::assertCount(1, $crawler->filter("#processo .ps-processos-lista > article[data-processo-id=\"{$pid}\"] + .ps-notas#notas-processo-{$pid}"));

        $bloco = $crawler->filter("#notas-processo-{$pid}");
        self::assertSame("/processos/{$pid}/nota-tecnica", $bloco->attr('data-url-criar'));
        self::assertSame((string) $pasta->getId(), $bloco->attr('data-pasta-id'));
        self::assertSame('', $bloco->attr('data-publicacao-id'));
        self::assertNotEmpty($bloco->attr('data-csrf-criar'));

        // Cabeçalho: título, contagem, o aviso de que a nota é do processo e o botão de adicionar.
        $cab = $bloco->filter('.ps-notas > .ps-notas-cab');
        self::assertCount(1, $cab);
        self::assertSame('Notas técnicas', trim($cab->filter('.ps-notas-titulo')->text()));
        self::assertSame('1', trim($cab->filter('.ps-notas-contagem')->text()));
        self::assertStringContainsString('aparece em toda pasta que o vincula', $cab->filter('.ps-notas-aviso')->text());
        self::assertCount(1, $cab->filter('.ps-notas-cab > .js-nota-nova'));

        // O compositor existe, fechado; a nota está na lista, com o selo e o texto.
        self::assertCount(1, $bloco->filter('.ps-notas > .ps-nota-editor[hidden] textarea'));
        $item = $bloco->filter(".ps-notas > .ps-notas-lista > #nota-tecnica-{$nota->getId()}");
        self::assertCount(1, $item);
        self::assertSame('Nota técnica', trim($item->filter('.ps-nota > .ps-nota-topo > .ps-nota-selo')->text()));
        self::assertSame('Admin Push', trim($item->filter('.ps-nota-topo > .ps-nota-autor')->text()));
        self::assertSame('AP', trim($item->filter('.ps-nota-topo > .ps-nota-avatar')->text()));
        self::assertStringContainsString('Prazo de 15 dias', $item->filter('.ps-nota > .ps-nota-texto')->text());
        // Nota sem publicação: nenhum rótulo de movimentação.
        self::assertCount(0, $item->filter('.ps-nota-mov'));
    }

    #[TestDox('a nota é do processo: aparece também em OUTRA pasta que vincula o mesmo processo')]
    public function testMesmaNotaEmOutraPastaDoMesmoProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $pastaA          = $this->criarPasta($tenant);
        $pastaB          = $this->criarPasta($tenant);
        $this->vincular($pastaA, $processo);
        $this->vincular($pastaB, $processo);
        $nota = $this->criarNota($processo, $user, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        foreach ([$pastaA, $pastaB] as $pasta) {
            $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter("#notas-processo-{$processo->getId()} > .ps-notas-lista > #nota-tecnica-{$nota->getId()}"), 'pasta ' . $pasta->getId());
        }
    }

    #[TestDox('nota pendurada numa movimentação diz qual, na aba Processo ("Intimação · 20/08/2026")')]
    public function testRotuloDaMovimentacaoNaAbaProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub  = $this->criarPublicacao($tenant, '60000001', self::NUMERO, '2026-08-20', $processo);
        $nota = $this->criarNota($processo, $user, $tenant, '<p>Sobre a intimação</p>', $pub);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $mov = $crawler->filter("#nota-tecnica-{$nota->getId()} > .ps-nota-topo > .ps-nota-mov");
        self::assertCount(1, $mov);
        self::assertSame('Intimação · 20/08/2026', trim($mov->text()));
    }

    #[TestDox('dentro dos 15 min o autor vê editar/excluir e "· 10 min"; passados 20 min, nada')]
    public function testJanelaDeEdicao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $recente = $this->criarNota($processo, $user, $tenant, '<p>Recente</p>', null, '-5 minutes');
        $antiga  = $this->criarNota($processo, $user, $tenant, '<p>Antiga</p>', null, '-20 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $acoes = $crawler->filter("#nota-tecnica-{$recente->getId()} > .ps-nota-topo > .ps-anotacao-acoes");
        self::assertCount(1, $acoes);
        self::assertCount(1, $acoes->filter('.ps-anotacao-acoes > .js-nota-editar[data-url][data-csrf][data-conteudo]'));
        self::assertCount(1, $acoes->filter('.ps-anotacao-acoes > .js-nota-excluir[data-url][data-csrf]'));
        self::assertSame('· 10 min', trim($acoes->filter('.ps-anotacao-acoes > .ps-anotacao-janela')->text()));

        self::assertCount(1, $crawler->filter("#nota-tecnica-{$antiga->getId()}"));
        self::assertCount(0, $crawler->filter("#nota-tecnica-{$antiga->getId()} .ps-anotacao-acoes"));
    }

    #[TestDox('quem não é o autor não vê editar/excluir, mesmo dentro da janela')]
    public function testNaoAutorNaoVeAcoes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $nota = $this->criarNota($processo, $outro, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertCount(1, $crawler->filter("#nota-tecnica-{$nota->getId()}"));
        self::assertCount(0, $crawler->filter("#nota-tecnica-{$nota->getId()} .ps-anotacao-acoes"));
    }

    #[TestDox('o teor do Push mostra só as notas DAQUELA movimentação, com o compositor apontando para o processo')]
    public function testTeorDoPushMostraSoAsNotasDaMovimentacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub1 = $this->criarPublicacao($tenant, '60000010', self::NUMERO, '2026-08-20', $processo);
        $pub2 = $this->criarPublicacao($tenant, '60000011', self::NUMERO, '2026-08-21', $processo);
        $daPub1     = $this->criarNota($processo, $user, $tenant, '<p>Da primeira</p>', $pub1);
        $daPub2     = $this->criarNota($processo, $user, $tenant, '<p>Da segunda</p>', $pub2);
        $soProcesso = $this->criarNota($processo, $user, $tenant, '<p>Só do processo</p>');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$pub1->getId()}");
        self::assertResponseIsSuccessful();

        $bloco = $crawler->filter(".ps-push-teor-corpo > .ps-notas#notas-publicacao-{$pub1->getId()}");
        self::assertCount(1, $bloco);
        self::assertSame((string) $processo->getId(), $bloco->attr('data-processo-id'));
        self::assertSame((string) $pub1->getId(), $bloco->attr('data-publicacao-id'));
        self::assertSame((string) $pasta->getId(), $bloco->attr('data-pasta-id'));
        self::assertStringContainsString('Sobre esta movimentação', $bloco->filter('.ps-notas-aviso')->text());

        self::assertCount(1, $bloco->filter(".ps-notas-lista > #nota-tecnica-{$daPub1->getId()}"));
        self::assertCount(0, $bloco->filter("#nota-tecnica-{$daPub2->getId()}"));
        self::assertCount(0, $bloco->filter("#nota-tecnica-{$soProcesso->getId()}"));
        // Dentro do teor da própria publicação o rótulo da movimentação é redundante.
        self::assertCount(0, $bloco->filter('.ps-nota-mov'));
    }

    #[TestDox('publicação sem a FK de processo (chegou antes do cadastro) ainda resolve o processo pelo número')]
    public function testTeorDePublicacaoSemFkResolveOProcessoPeloNumero(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $semFk = $this->criarPublicacao($tenant, '60000020', self::NUMERO, '2026-08-20');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/push/{$semFk->getId()}");
        self::assertResponseIsSuccessful();

        $bloco = $crawler->filter(".ps-push-teor-corpo > .ps-notas#notas-publicacao-{$semFk->getId()}");
        self::assertCount(1, $bloco);
        self::assertSame((string) $processo->getId(), $bloco->attr('data-processo-id'));
    }
}
