<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Pasta\UseCase\MontarPainelDoPushUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A coluna direita da aba Push (desenho 1.2.3, dc L.2567-2626 e L.6595; spec
 * push-painel-monitoramento §4): a grade de duas colunas por filho direto, o cartão com os textos
 * de cada estado e a Situação atual com a taxa de acertos gravada uma vez só.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(MontarPainelDoPushUseCase::class)]
#[Group('pasta')]
final class PastaPushPainelTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const PAINEL = '#push > .ps-push-grade > aside.ps-push-coluna > .ps-push-painel';

    private function abrir(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, User $user, \App\Entity\Tenant\Tenant $tenant, int $pastaId): Crawler
    {
        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pastaId);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function contarPrevisoes(int $processoId): int
    {
        return (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM previsao_processual WHERE processo_id = :p', ['p' => $processoId]);
    }

    #[TestDox('Grade do desenho: a lista e a coluna do painel são filhas diretas da grade, nessa ordem')]
    public function testGradeDeDuasColunas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $crawler = $this->abrir($client, $user, $tenant, (int) $pasta->getId());

        $filhos = $crawler->filter('#push > .ps-push-grade > *')->reduce(
            static fn (Crawler $no): bool => !in_array($no->nodeName(), ['link', 'script'], true),
        );
        self::assertSame(['section', 'aside'], $filhos->each(static fn (Crawler $no): string => $no->nodeName()));
        self::assertCount(1, $crawler->filter('#push > .ps-push-grade > section.ps-card.ps-push'));
        self::assertCount(1, $crawler->filter(self::PAINEL));
    }

    #[TestDox('Sem processo: "Sem monitoramento" / "Vincule um processo", switch e Buscar agora desabilitados')]
    public function testSemProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $painel = $this->abrir($client, $user, $tenant, (int) $pasta->getId())->filter(self::PAINEL);

        self::assertSame('Sem monitoramento', $painel->filter('.ps-push-painel-titulo')->text());
        self::assertSame('Vincule um processo', $painel->filter('.ps-push-painel-sub')->text());
        self::assertSame('Sem processo vinculado', $painel->filter('.ps-push-painel-processo')->text());
        self::assertNotNull($painel->filter('[role="switch"][aria-label="Monitoramento"]')->attr('disabled'));
        self::assertNotNull($painel->filter('.ps-push-buscar')->attr('disabled'));
        self::assertCount(0, $painel->filter('.ps-push-sit'));
    }

    #[TestDox('Ativo: textos do desenho, ponto ativo, "nº · tribunal", última verificação e Buscar agora habilitado')]
    public function testAtivo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $processo->setSiglaTribunal('TJDFT');
        $processo->registrarVerificacao(new \DateTimeImmutable('today 09:12'));
        $this->vincular($pasta, $processo);

        $painel = $this->abrir($client, $user, $tenant, (int) $pasta->getId())->filter(self::PAINEL);

        self::assertSame('Monitoramento ativo', $painel->filter('.ps-push-painel-titulo')->text());
        self::assertSame('Captura automática nos diários e no PJe', $painel->filter('.ps-push-painel-sub')->text());
        self::assertCount(1, $painel->filter('.ps-push-painel-cab > .ps-push-painel-ponto.is-ativo'));
        $chave = $painel->filter('.ps-push-painel-cab > button[role="switch"]');
        self::assertSame('true', $chave->attr('aria-checked'));
        self::assertNull($chave->attr('disabled'));
        $linhaPrincipal = $painel->filter('.ps-push-painel-info > .ps-push-painel-linha')->first();
        self::assertSame('0701134-57.2025.8.07.0007 · TJDFT', $linhaPrincipal->filter('.ps-push-painel-processo')->text(), 'com a máscara CNJ');
        self::assertSame('Principal', $linhaPrincipal->filter('.ps-push-painel-processo + .ps-push-painel-selo')->text());
        self::assertCount(0, $painel->filter('.ps-push-apensado'), 'um processo só: sem linhas recuadas');
        self::assertStringContainsString('Última verificação: hoje, 09:12', $painel->filter('.ps-push-painel-info')->text());
        $buscar = $painel->filter('button.ps-push-buscar');
        self::assertSame('Buscar agora', $buscar->text());
        self::assertSame('Busca, lê e audita o processo: prazos, decursos, pendências e próximos atos', $buscar->attr('title'));
        self::assertCount(1, $buscar->filter('i.bi-cloud-download'));
        self::assertNull($buscar->attr('disabled'));
    }

    #[TestDox('Pausado: textos do desenho, ponto cinza, Buscar agora desabilitado e Situação atual escondida')]
    public function testPausado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $processo->pausarMonitoramento($user, new \DateTimeImmutable());
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '61000001', self::NUMERO_DA_PASTA, '2026-10-14', $processo)->setTexto('Sentença publicada.');
        $this->em()->flush();

        $painel = $this->abrir($client, $user, $tenant, (int) $pasta->getId())->filter(self::PAINEL);

        self::assertSame('Monitoramento pausado', $painel->filter('.ps-push-painel-titulo')->text());
        self::assertSame('Novas movimentações não serão capturadas', $painel->filter('.ps-push-painel-sub')->text());
        self::assertCount(0, $painel->filter('.ps-push-painel-ponto.is-ativo'));
        self::assertSame('false', $painel->filter('[role="switch"]')->attr('aria-checked'));
        self::assertNotNull($painel->filter('.ps-push-buscar')->attr('disabled'));
        self::assertNotNull($painel->filter('.ps-push-sit')->attr('hidden'), 'some com o monitoramento pausado (dc L.5420)');
        self::assertSame(0, $this->contarPrevisoes((int) $processo->getId()), 'pausado não grava previsão');
    }

    #[TestDox('Quem só vê a pasta não alterna o monitoramento: o switch sai desabilitado')]
    public function testSoLeitorNaoAlterna(): void
    {
        $client   = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $leitor   = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta    = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));

        $painel = $this->abrir($client, $leitor, $tenant, (int) $pasta->getId())->filter(self::PAINEL);

        self::assertNotNull($painel->filter('[role="switch"]')->attr('disabled'));
        self::assertNull($painel->filter('.ps-push-buscar')->attr('disabled'), 'buscar exige só ver');
    }

    #[TestDox('Situação atual: campos e textos do desenho, e a previsão é gravada uma vez só (recarregar não duplica)')]
    public function testSituacaoAtual(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '61000002', self::NUMERO_DA_PASTA, '2026-09-25', $processo)->setTexto('Juntada de contestação pela parte ré.');
        $this->criarPublicacao($tenant, '61000003', self::NUMERO_DA_PASTA, '2026-10-02', $processo)->setTexto('<p>Intime-se a parte autora para manifestação sobre a contestação.</p>');
        $this->em()->flush();

        $sit = $this->abrir($client, $user, $tenant, (int) $pasta->getId())->filter(self::PAINEL . ' > .ps-push-sit');

        self::assertCount(1, $sit);
        self::assertNull($sit->attr('hidden'));
        self::assertSame('Situação atual', $sit->filter('.ps-push-sit-rotulo')->first()->text());
        self::assertSame('Prazo para réplica', $sit->filter('.ps-push-sit-titulo')->text());
        self::assertSame(
            ['Último evento', 'Próximo ato', 'Quem age', 'Prazo', 'Previsão'],
            $sit->filter('.ps-push-sit-grade > .ps-push-sit-k')->each(static fn (Crawler $k): string => $k->text()),
        );
        self::assertSame('02/10/2026 · Intimação', $sit->filter('.ps-push-sit-grade > .ps-push-sit-v')->first()->text());
        self::assertStringStartsWith('26/10/2026', $sit->filter('.ps-push-sit-previsao')->text());
        self::assertStringStartsWith('Contagem em dias úteis a partir do 1º dia útil após a publicação', (string) $sit->filter('.ps-push-sit-previsao')->attr('title'));
        self::assertSame('Próxima etapa provável', $sit->filter('.ps-push-sit-proxima .ps-push-sit-rotulo')->text());
        self::assertSame('Confiança Alta', $sit->filter('.ps-push-sit-conf-selo--alta')->text());
        self::assertSame('Base: CPC arts. 350 e 351 · histórico de 2 movimentações', $sit->filter('.ps-push-sit-base')->text());
        self::assertSame('Previsão da I.A, não é ato oficial. Ainda sem previsão anterior para conferir.', $sit->filter('.ps-push-sit-rodape')->text());
        self::assertSame(1, $this->contarPrevisoes((int) $processo->getId()));

        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contarPrevisoes((int) $processo->getId()), 'recarregar não grava de novo');
    }

    #[TestDox('Vários processos: o principal com o selo, os demais recuados com sublinha e switch próprio; " · 2 processos" no subtítulo')]
    public function testOutrosProcessosDaPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));
        $apensado = $this->criarProcesso($tenant, '00000904420265180301');
        $apensado->setSiglaTribunal('TRT18');
        $apensado->pausarMonitoramento($user, new \DateTimeImmutable('2026-10-18 15:00'));
        $this->vincular($pasta, $apensado);

        $painel = $this->abrir($client, $user, $tenant, (int) $pasta->getId())->filter(self::PAINEL);

        self::assertSame('Captura automática nos diários e no PJe · 2 processos', $painel->filter('.ps-push-painel-sub')->text());
        $linha = $painel->filter('.ps-push-painel-info > .ps-push-apensado[data-push-painel-processo="' . $apensado->getId() . '"]');
        self::assertCount(1, $linha);
        self::assertSame('0000090-44.2026.5.18.0301 · TRT18', $linha->filter('.ps-push-apensado-numero')->text());
        self::assertSame('Apensado: PROCEDIMENTO COMUM · pausado', $linha->filter('.ps-push-apensado-sub')->text());
        $chave = $linha->filter('.ps-push-apensado-texto + button[role="switch"]');
        self::assertSame('false', $chave->attr('aria-checked'));
        self::assertSame((string) $apensado->getId(), $chave->attr('data-processo-id'));
        self::assertSame('Pausado por Admin Push em 18/10', $chave->attr('title'));
        self::assertNull($chave->attr('disabled'));
        self::assertSame('true', $painel->filter('.ps-push-painel-cab > button[role="switch"]')->attr('aria-checked'), 'o do cabeçalho é o do principal');
    }

    #[TestDox('Pasta excluída: switches travados com a dica "Pasta excluída: somente leitura." e o GET não grava previsão')]
    public function testPastaExcluida(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '61000010', self::NUMERO_DA_PASTA, '2026-10-14', $processo)->setTexto('Sentença publicada.');
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $painel = $this->abrir($client, $user, $tenant, (int) $pasta->getId())->filter(self::PAINEL);

        $chave = $painel->filter('.ps-push-painel-cab > button[role="switch"]');
        self::assertNotNull($chave->attr('disabled'));
        self::assertSame('Pasta excluída: somente leitura.', $chave->attr('title'));
        self::assertNotNull($painel->filter('.ps-push-buscar')->attr('disabled'));
        self::assertCount(1, $painel->filter('.ps-push-sit'), 'a situação continua visível para leitura');
        self::assertSame(0, $this->contarPrevisoes((int) $processo->getId()));
    }

    #[TestDox('Taxa de acertos: chega a movimentação seguinte, a página confere a previsão anterior e mostra "Previsões conferidas: 1 de 1 acertaram"')]
    public function testTaxaDeAcertosAoMudarAUltimaMovimentacao(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '61000020', self::NUMERO_DA_PASTA, '2026-09-01', $processo)->setTexto('Sentença publicada.');
        $this->em()->flush();
        $pastaId = (int) $pasta->getId();

        $rodape = $this->abrir($client, $user, $tenant, $pastaId)->filter(self::PAINEL . ' .ps-push-sit-rodape')->text();
        self::assertSame('Previsão da I.A, não é ato oficial. Ainda sem previsão anterior para conferir.', $rodape);

        // A sentença previu "Apelação ou embargos de declaração"; chega a apelação.
        $this->criarPublicacao($tenant, '61000021', self::NUMERO_DA_PASTA, '2026-10-14', $processo)->setTexto('Apelação interposta pela parte ré.');
        $this->em()->flush();

        $crawler = $client->request('GET', '/pasta/' . $pastaId);
        self::assertResponseIsSuccessful();
        self::assertSame('Previsão da I.A, não é ato oficial. Previsões conferidas: 1 de 1 acertaram.', $crawler->filter(self::PAINEL . ' .ps-push-sit-rodape')->text());
        self::assertSame('Prazo para contrarrazões', $crawler->filter(self::PAINEL . ' .ps-push-sit-titulo')->text());
        self::assertSame(2, $this->contarPrevisoes((int) $processo->getId()));

        $linhas = static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT chave, conferida, acertou FROM previsao_processual WHERE pasta_id = :p ORDER BY id',
            ['p' => $pastaId],
        );
        self::assertSame(['01/09/2026 Intimação', '14/10/2026 Intimação'], array_column($linhas, 'chave'));
        self::assertTrue($linhas[0]['conferida']);
        self::assertTrue($linhas[0]['acertou']);
        self::assertFalse($linhas[1]['conferida']);

        $client->request('GET', '/pasta/' . $pastaId);
        self::assertStringContainsString('Previsões conferidas: 1 de 1 acertaram', (string) $client->getResponse()->getContent(), 'reabrir não confere de novo');
    }

    #[TestDox('O histórico vem do banco: sem contestação anterior, a réplica leva a nota do desenho; a contagem é a da lista')]
    public function testHistoricoSemContestacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '61000030', self::NUMERO_DA_PASTA, '2026-09-20', $processo)->setTexto('Despacho de mero expediente.');
        $this->criarPublicacao($tenant, '61000031', self::NUMERO_DA_PASTA, '2026-09-25', $processo)->setTexto('Juntada de procuração.');
        $this->criarPublicacao($tenant, '61000032', self::NUMERO_DA_PASTA, '2026-10-02', $processo)->setTexto('Manifestação sobre a contestação.');
        $this->em()->flush();

        $sit = $this->abrir($client, $user, $tenant, (int) $pasta->getId())->filter(self::PAINEL . ' > .ps-push-sit');

        self::assertSame('Não encontrei a contestação no histórico; confira se ela foi juntada.', $sit->filter('.ps-push-sit-nota span')->text());
        self::assertSame('Confiança Média', $sit->filter('.ps-push-sit-conf-selo')->text());
        self::assertSame('Base: CPC arts. 350 e 351 · histórico de 3 movimentações', $sit->filter('.ps-push-sit-base')->text());
    }
}
