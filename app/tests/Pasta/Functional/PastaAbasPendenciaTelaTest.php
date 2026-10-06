<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Tarefa\Tarefa;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A linha vermelha sob as abas da pasta (desenho "02 - EXPEDIENTES 1.2.3", padrão
 * PJe) só pode existir com pendência REAL por trás: meta aberta, pasta sem
 * processo, contrato pendente sem pró-bono, publicação do Push não lida. Sinal
 * decorativo seria mentira na tela — e é exatamente o que a regra "nada fake" da
 * Trilha A proíbe. A regra em si vive em `PastaPendenciasOutput` (teste unitário
 * próprio); aqui se prova que a TELA a mostra no lugar certo.
 *
 * Cada regra é provada nos DOIS sentidos: o caso que acende a barra e o caso em
 * que o filtro remove tudo (meta concluída, publicação lida, pró-bono). Teste de
 * filtro que só prova o caso cheio já reabriu defeito neste projeto. E o caso
 * MISTO (uma meta aberta entre duas) prova que a pendência e o selo são contas
 * diferentes.
 *
 * O selo da aba Push passa a contar só as NÃO LIDAS, como no desenho: o total
 * continua na própria aba, e o selo responde "o que ainda não vi".
 *
 * Tudo por combinador de FILHO DIRETO a partir de `#pastaTabs`: "existe na
 * página" não distingue a barra na aba certa de uma barra solta.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaAbasPendenciaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';

    // =========================================================================
    // Metas
    // =========================================================================

    #[TestDox('meta NÃO concluída acende a linha vermelha da aba Metas, e o title explica o motivo')]
    public function testMetaAbertaMarcaPendencia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarMeta($pasta, $user, Tarefa::STATUS_PENDENTE);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $aba = $crawler->filter('#pastaTabs > #tarefas-tab.ps-aba--pend');
        self::assertCount(1, $aba, 'a aba Metas é filha direta do trilho e carrega a marca de pendência');
        self::assertCount(1, $aba->filter('#tarefas-tab > .ps-aba-pend'), 'a barra vermelha é filha direta da aba');
        self::assertStringContainsString('1 meta exige atenção', (string) $aba->attr('title'));
        self::assertSame('1', trim($aba->filter('.ps-aba-badge')->text()), 'o selo continua sendo o TOTAL de metas');
    }

    #[TestDox('uma meta aberta entre duas: a pendência conta 1 (plural não), o selo conta 2 — são contas diferentes')]
    public function testMetaAbertaEntreDuasContaSoAAberta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarMeta($pasta, $user, Tarefa::STATUS_CONCLUIDA);
        $this->criarMeta($pasta, $user, Tarefa::STATUS_EM_REVISAO);
        $this->criarMeta($pasta, $user, Tarefa::STATUS_PENDENTE);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $aba = $crawler->filter('#pastaTabs > #tarefas-tab.ps-aba--pend');
        self::assertCount(1, $aba);
        self::assertStringContainsString('2 metas exigem atenção', (string) $aba->attr('title'), 'em revisão ainda não é concluída; plural');
        self::assertSame('3', trim($aba->filter('.ps-aba-badge')->text()));
    }

    #[TestDox('meta concluída não acende nada — o filtro remove tudo e o selo continua contando o total')]
    public function testMetaConcluidaNaoMarcaPendencia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarMeta($pasta, $user, Tarefa::STATUS_CONCLUIDA);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#pastaTabs > #tarefas-tab.ps-aba--pend'));
        self::assertCount(0, $crawler->filter('#tarefas-tab > .ps-aba-pend'));
        self::assertStringNotContainsString('exige', (string) $crawler->filter('#tarefas-tab')->attr('title'));
        self::assertSame('1', trim($crawler->filter('#tarefas-tab .ps-aba-badge')->text()));
    }

    // =========================================================================
    // Processo
    // =========================================================================

    #[TestDox('pasta sem processo vinculado acende a aba Processo; vinculado, a marca some')]
    public function testProcessoSemVinculoMarcaPendencia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $aba = $crawler->filter('#pastaTabs > #processo-tab.ps-aba--pend');
        self::assertCount(1, $aba);
        self::assertStringContainsString('nenhum processo vinculado', (string) $aba->attr('title'));

        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO));

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#pastaTabs > #processo-tab.ps-aba--pend'));
        self::assertCount(0, $crawler->filter('#processo-tab > .ps-aba-pend'));
        self::assertStringNotContainsString('nenhum processo', (string) $crawler->filter('#processo-tab')->attr('title'));
    }

    // =========================================================================
    // Financeiro
    // =========================================================================

    #[TestDox('contrato pendente acende a aba Financeiro; pró-bono ou contrato regular apagam — barra, classe e title')]
    public function testContratoPendenteMarcaFinanceiro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        self::assertSame('PENDENTE', $pasta->getSituacaoContrato(), 'premissa: pasta nova nasce com contrato pendente');

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $aba = $crawler->filter('#pastaTabs > #financeiro-tab.ps-aba--pend');
        self::assertCount(1, $aba);
        self::assertCount(1, $aba->filter('#financeiro-tab > .ps-aba-pend'));
        self::assertStringContainsString('contrato de honorários pendente', (string) $aba->attr('title'));

        // Pró-bono regulariza mesmo com o contrato pendente (regra primária do desenho).
        $pasta->setProBono(true);
        $this->em()->flush();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(0, $crawler->filter('#pastaTabs > #financeiro-tab.ps-aba--pend'), 'pró-bono: sem pendência');
        self::assertCount(0, $crawler->filter('#financeiro-tab > .ps-aba-pend'));
        self::assertStringNotContainsString('pendente', (string) $crawler->filter('#financeiro-tab')->attr('title'));

        // Contrato regular, sem pró-bono: também sem pendência.
        $pasta->setProBono(false);
        $pasta->setSituacaoContrato('REGULAR');
        $this->em()->flush();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(0, $crawler->filter('#pastaTabs > #financeiro-tab.ps-aba--pend'), 'contrato regular: sem pendência');
        self::assertCount(0, $crawler->filter('#financeiro-tab > .ps-aba-pend'));
        self::assertStringNotContainsString('pendente', (string) $crawler->filter('#financeiro-tab')->attr('title'));
    }

    // =========================================================================
    // Push Processual
    // =========================================================================

    #[TestDox('o selo da aba Push conta só as publicações NÃO LIDAS, e é isso que acende a pendência')]
    public function testPushContaSoNaoLidas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $lida = $this->criarPublicacao($tenant, '30000001', self::NUMERO, '2026-08-20', $processo);
        $this->criarPublicacao($tenant, '30000002', self::NUMERO, '2026-08-28', $processo);
        $lida->setLida(true);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertSame(2, $crawler->filter('.ps-push-lista > .ps-push-item')->count(), 'a lista continua com as duas');
        self::assertSame('1', trim($crawler->filter('#push-tab .ps-aba-badge')->text()), 'o selo é só a não lida');

        $aba = $crawler->filter('#pastaTabs > #push-tab.ps-aba--pend');
        self::assertCount(1, $aba);
        self::assertStringContainsString('1 movimentação nova sem leitura', (string) $aba->attr('title'));
    }

    #[TestDox('duas não lidas: selo 2 e title no plural')]
    public function testPushDuasNaoLidasNoPlural(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '30000005', self::NUMERO, '2026-08-20', $processo);
        $this->criarPublicacao($tenant, '30000006', self::NUMERO, '2026-08-28', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertSame('2', trim($crawler->filter('#push-tab .ps-aba-badge')->text()));
        self::assertStringContainsString('2 movimentações novas sem leitura', (string) $crawler->filter('#pastaTabs > #push-tab.ps-aba--pend')->attr('title'));
    }

    #[TestDox('com todas as publicações lidas o selo some e a aba Push fica sem pendência')]
    public function testPushTodasLidasSemSeloESemPendencia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '30000003', self::NUMERO, '2026-08-20', $processo)->setLida(true);
        $this->criarPublicacao($tenant, '30000004', self::NUMERO, '2026-08-28', $processo)->setLida(true);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertSame(2, $crawler->filter('.ps-push-lista > .ps-push-item')->count());
        self::assertCount(0, $crawler->filter('#push-tab .ps-aba-badge'), 'zero não lidas = sem selo (contagem nunca é literal)');
        self::assertCount(0, $crawler->filter('#pastaTabs > #push-tab.ps-aba--pend'));
    }

    // =========================================================================
    // Dados, Detalhes e Documentos
    // =========================================================================

    #[TestDox('Dados e Detalhes nunca carregam a marca; Documentos, sem checklist marcado sem anexo, também não (a regra dela está em PastaChecklistTelaTest)')]
    public function testAbasSemRegraNaoMarcam(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        foreach (['dados', 'detalhes', 'documentos'] as $id) {
            self::assertCount(0, $crawler->filter('#pastaTabs > #' . $id . '-tab.ps-aba--pend'), $id);
            self::assertCount(0, $crawler->filter('#' . $id . '-tab > .ps-aba-pend'), $id);
        }
        // E as sete abas continuam filhas diretas do trilho, com o indicador junto.
        self::assertCount(7, $crawler->filter('#pastaTabs > button.ps-aba'));
        self::assertCount(1, $crawler->filter('#pastaTabs > .ps-abas-ind'));
    }

    // =========================================================================
    // Fixtures locais
    // =========================================================================

    private function criarMeta(\App\Pasta\Entity\Pasta $pasta, \App\Entity\Auth\User $user, string $status): void
    {
        $meta = new Tarefa();
        $meta->setTitulo('Protocolar contestação');
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+5 days'));
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($user);
        $meta->addResponsavel($user);
        $meta->setStatus($status);
        $this->em()->persist($meta);
        // Lado inverso: a Pasta já está na identity map, e a coleção não se
        // atualiza sozinha ao persistir o lado dono.
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();
    }
}
