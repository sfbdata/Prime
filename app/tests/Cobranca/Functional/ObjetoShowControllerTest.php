<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoVinculo;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\LiquidacaoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use App\Tests\Factory\Cobranca\ProximaAcaoFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 2 do ajuste 2: a página unificada do objeto (`cobranca_objeto_show`). Prova que abrir o objeto
 * renderiza o corpo da cobrança, que o acesso é tenant-safe (404 cross-tenant), e que o deep-link antigo
 * do caso redireciona para o objeto.
 *
 * Ajuste 10: a página foi reorganizada pelo TRABALHO, não pelas tabelas — 3 abas (Cobrança · Documentos ·
 * Histórico), a pessoa cobrada virou card (deixou de ser aba) e a dívida é uma lista só.
 */
#[CoversClass(ObjetoController::class)]
#[CoversClass(CasoController::class)]
final class ObjetoShowControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Objeto show: renderiza identificação, abas e os vínculos da pessoa')]
    public function testShowRenderizaPaginaUnificada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoa' => $caso->getPessoaCobradaAtual(),
            'tipoVinculo' => TipoVinculo::Proprietario,
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $objeto->getIdentificacao(), $html);
        self::assertStringContainsString('Dívida em aberto', $html);
        self::assertStringContainsString('Histórico', $html);
        self::assertStringContainsString('Proprietário', $html);
    }

    #[TestDox('Ajuste 10: a página tem exatamente 3 abas e a pessoa cobrada virou card, não aba')]
    public function testPaginaDoObjetoTemAsTresAbasEOCardDaPessoa(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('#objetoTabs .nav-link'), 'devem ser exatamente 3 abas');
        self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-cobranca"]');
        self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-documentos"]');
        self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-historico"]');
        // Pessoa deixou de ser aba e virou card.
        self::assertSelectorNotExists('#objetoTabs [data-bs-target="#tab-pessoas"]');
        self::assertSelectorExists('.jp-pessoa-card');
        // A subnav do módulo voltou (B3): esta página era a única que a perdia.
        self::assertSelectorExists('.cobranca-subnav');
    }

    #[TestDox('Ajuste 10: a aba Cobrança abre por padrão — o cadastro deixou de ser a primeira coisa')]
    public function testAbaCobrancaAbrePorPadrao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            '#tab-cobranca',
            $crawler->filter('#objetoTabs .nav-link.active')->attr('data-bs-target'),
        );
        self::assertSelectorExists('#tab-cobranca.show.active');
    }

    #[TestDox('Ajuste 10: a dívida mostra quanto FALTA quando há pagamento parcial')]
    public function testADividaMostraOQuantoFaltaQuandoHaPagamentoParcial(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota condominial', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 40000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 40000,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // 1.200,00 devidos - 400,00 recebidos = faltam 800,00 (T1: `restante()`).
        self::assertStringContainsString('800,00', $crawler->filter('.jp-obr-restante')->text());
        self::assertStringContainsString('400,00', $crawler->filter('.jp-obr-sub')->text());
    }

    #[TestDox('Ajuste 10: a dívida declara a ordem (mais antiga primeiro) e mostra a data em coluna')]
    public function testDividaTemEixoTemporalEDeclaraAOrdem(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela antiga',
            'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-03-10'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // O FIFO abate a mais antiga primeiro: a lista é uma fila e diz isso (spec §4.7).
        self::assertStringContainsString(
            'Da mais antiga para a mais nova',
            $crawler->filter('#secao-divida .jp-ordem')->text(),
        );
        self::assertStringContainsString('10/03/2026', $crawler->filter('.jp-obr-data-dia')->text());
        self::assertStringContainsString('há ', $crawler->filter('.jp-obr-data-rel')->text());
    }

    #[TestDox('Caso encerrado: some o que muta a cobrança, mas o cadastro de envolvidos continua corrigível')]
    public function testCasoEncerradoEsconderApenasOQueOServidorBloqueia(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Trocar a pessoa cobrada é mutação da cobrança: sai de cena num caso encerrado.
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalAlterarPessoa"]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalRegistrarObrigacao"]'));
        // Já o CADASTRO de envolvidos não depende do caso estar aberto — os UseCases de vínculo não olham
        // o encerramento, e a UI não pode ser mais restritiva que o servidor (era assim antes do Ajuste 10).
        self::assertGreaterThan(0, $crawler->filter('[data-bs-target="#modalVincularPessoaObjeto"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-bs-target="#modalNovaPessoa"]')->count());
    }

    #[TestDox('Ajuste 10: sem movimentação financeira o extrato não oferece registrar pagamento')]
    public function testSemCapacidadeFinanceiraNaoOfereceRegistrarPagamento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // `gerenciar` e `movimentacao_financeira` são capacidades SEPARADAS (SPEC §22).
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalRegistrarPagamento"]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalRegistrarLiquidacao"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-bs-target="#modalRegistrarObrigacao"]')->count());
    }

    #[TestDox('Objeto show cross-tenant: 404')]
    public function testShowCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('GET', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Ajuste 10 fix F5: o vínculo encerrado mostra o motivo do encerramento, escapado')]
    public function testVinculoEncerradoMostraOMotivoDoEncerramento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoa' => $caso->getPessoaCobradaAtual(),
            'tipoVinculo' => TipoVinculo::Representante,
            'dataFim' => new \DateTimeImmutable('-1 day'),
            'motivoEncerramento' => 'Fiança <quitada> & liberada',
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.jp-vinculo-linha.encerrado')->text();
        self::assertStringContainsString('Fiança <quitada> & liberada', $linha);
        // Confere que o motivo foi escapado no HTML bruto (nunca `|raw`).
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Fiança &lt;quitada&gt; &amp; liberada', $html);
    }

    #[TestDox('Ajuste 10 fix F2: o extrato funde pagamentos e liquidações do mais recente para o mais antigo')]
    public function testExtratoDeMovimentosOrdenaDoMaisRecenteParaOMaisAntigo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Datas distintas e conhecidas: mais antigo → mais novo é 10/01, 05/02, 20/03.
        PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'data' => new \DateTimeImmutable('2026-01-10'),
            'valorDivida' => 10000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ]);
        LiquidacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'data' => new \DateTimeImmutable('2026-02-05'),
            'descricaoBem' => 'Veículo dado em pagamento',
            'valorAtribuidoBem' => 20000, 'valorReconhecido' => 20000,
        ]);
        PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'data' => new \DateTimeImmutable('2026-03-20'),
            'valorDivida' => 30000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Do mais recente para o mais antigo',
            $crawler->filter('#secao-movimentos .jp-ordem')->text(),
        );

        $valores = $crawler->filter('#secao-movimentos .jp-mov .jp-obr-valor')
            ->each(static fn ($node) => trim($node->text()));

        self::assertCount(3, $valores);
        // Ordem esperada: 20/03 (300,00) → 05/02 (200,00) → 10/01 (100,00).
        self::assertStringContainsString('300,00', $valores[0]);
        self::assertStringContainsString('200,00', $valores[1]);
        self::assertStringContainsString('100,00', $valores[2]);
    }

    #[TestDox('Ajuste 10 fix F1: alerta de ação atrasada oferece "Concluir" apontando para o modal')]
    public function testAlertaDeAcaoAtrasadaOfereceBotaoConcluir(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ProximaAcaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Ligar para o devedor',
            'prazo' => new \DateTimeImmutable('-3 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('.jp-alerta [data-bs-target="#modalConcluirAcao"]');
        self::assertGreaterThan(0, $botao->count(), 'o alerta de ação atrasada deve trazer o botão Concluir');
        self::assertStringContainsString('Concluir', $botao->text());
    }

    #[TestDox('Ajuste 10 fix F4: obrigação avulsa quitada com vencimento passado não é marcada como atrasada')]
    public function testObrigacaoQuitadaComVencimentoPassadoNaoFicaAtrasada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota condominial quitada', 'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-10 days'),
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 50000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 50000,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Vencida no passado, mas quitada: não é "atrasada" — quem já pagou não fica em vermelho.
        self::assertCount(0, $crawler->filter('.jp-obr-data-rel.is-atrasado'));
        self::assertGreaterThan(0, $crawler->filter('.jp-chip.is-paga')->count());
    }

    #[TestDox('Deep-link do caso redireciona (302) para a página do objeto (Fatia 5)')]
    public function testCasoShowRedirecionaParaObjeto(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->request('GET', '/cobrancas/casos/' . $caso->getId());

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
    }
}
