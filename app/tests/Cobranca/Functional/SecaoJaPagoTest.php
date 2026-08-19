<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * R5 (spec `cobranca-importar-receitas.md` §3.1) — a aba Dívida separa o que se COBRA do que já foi PAGO.
 *
 * Por que isto é condição da etapa 2 e não enfeite: a importação de receitas cria a obrigação que o
 * sistema não conhecia, JÁ PAGA — ~2.078 delas contra 3.431 em aberto, medido nos arquivos de 03/08. Sem
 * a separação, um devedor com 7 boletos pagos e 3 em aberto passa a mostrar 10 linhas com as 3 que
 * importam no meio, e a tela deixa de responder "o que eu cobro dele?".
 *
 * O que cada teste guarda:
 *  - a PARTIÇÃO em si (a paga sai da fila, a em aberto fica) e a régua que a decide;
 *  - o TOTAL da seção ser o RECEBIDO, não o cobrado — é ele que tem de bater com a coluna
 *    `Valor recebido` da contabilidade (spec §8);
 *  - que os números do CABEÇALHO não se moveram: a partição é adicional, e o dono não pediu para o
 *    saldo nem os cards mudarem;
 *  - o isolamento por tenant do mapa de datas em lote — no contexto CLI, que é onde ele importa.
 */
final class SecaoJaPagoTest extends CobrancaWebTestCase
{
    #[TestDox('R5: a obrigação paga sai da fila de cobrança e aparece na seção "Já pago"')]
    public function testObrigacaoPagaDesceParaASecaoPropriaEAEmAbertoFicaNaFila(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->obrigacaoQuitada($tenant, $caso, 'Taxa 01/2026', 100000, new \DateTimeImmutable('2026-01-05'));
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 07/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-5 days'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();

        // A fila de cobrança tem UMA linha, e é a que está em aberto.
        $fila = $crawler->filter('#secao-divida .jp-obr');
        self::assertCount(1, $fila, 'a fila de cobrança lista só o que está em aberto');
        self::assertStringContainsString('Taxa 07/2026', $fila->text());
        self::assertStringNotContainsString('Taxa 01/2026', $fila->text());

        // A seção "Já pago" tem a outra — e não a de cobrança.
        $pagas = $crawler->filter('#secao-ja-pago .jp-pagas-linha');
        self::assertCount(1, $pagas, 'a seção "Já pago" lista só o que está quitado');
        self::assertStringContainsString('Taxa 01/2026', $pagas->text());
        self::assertStringNotContainsString('Taxa 07/2026', $pagas->text());

        // A data exibida é a do PAGAMENTO, não a do vencimento — é a pergunta que a seção responde.
        self::assertStringContainsString('05/01/2026', $pagas->filter('.jp-pagas-data')->text());
    }

    #[TestDox('R5: sem nada pago, a seção "Já pago" não nasce')]
    public function testSemObrigacaoPagaASecaoNaoAparece(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 07/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#secao-ja-pago'));
        self::assertCount(1, $crawler->filter('#secao-divida .jp-obr'));
    }

    #[TestDox('R5: obrigação PARCIALMENTE paga continua na fila de cobrança — ela ainda se cobra')]
    public function testObrigacaoParcialmentePagaNaoDesceParaOHistorico(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 03/2026', 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorDivida' => 40000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 40000,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // Falta R$ 600,00: é dívida viva, e a seção de histórico não pode engoli-la.
        self::assertCount(0, $crawler->filter('#secao-ja-pago'));
        self::assertCount(1, $crawler->filter('#secao-divida .jp-obr'));
        self::assertStringContainsString('400,00 já recebidos', $crawler->filter('#secao-divida .jp-obr')->text());
    }

    #[TestDox('R5: o total da seção é o RECEBIDO (Σ alocado), não o total cobrado das linhas')]
    public function testTotalDaSecaoEhORecebidoENaoOCobrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Carteira que cobra 20% de honorário sobre o principal (juros/multa 0 na fixture), logo o
        // exigível é principal × 1,20.
        //
        // ⚠️ O cenário ORIGINAL fazia recebido e cobrado divergirem porque o honorário ficava FORA do
        // exigível: R$ 1.000,00 alocados quitavam uma linha de R$ 1.200,00. Essa divergência ERA o
        // defeito, e a spec `cobranca-honorario-no-total.md` a fechou — hoje quem paga R$ 1.000,00 de
        // uma dívida de R$ 1.200,00 simplesmente não quitou. A pergunta do teste continua válida, e a
        // divergência que resta é a que o próprio `CasoDetalheOutput` documenta: a obrigação
        // SUPER-ALOCADA (recebeu mais do que devia). É nela que o cenário se apoia agora.
        [, $caso] = $this->semearGrafo($tenant, [], self::carteiraQueCobraHonorarios());

        // Quitada exata: exigível 120000 (100000 + 20% de honorário), alocado 120000.
        $this->obrigacaoQuitada($tenant, $caso, 'Taxa 01/2026', 100000, new \DateTimeImmutable('2026-01-05'), 120000);
        // SUPER-ALOCADA: exigível 30000 (25000 + 20%), recebeu 35000.
        $this->obrigacaoQuitada($tenant, $caso, 'Taxa 02/2026', 25000, new \DateTimeImmutable('2026-02-05'), 35000);

        $detalhe = static::getContainer()->get(MontarDetalheCasoUseCase::class)->executar($caso);

        self::assertCount(2, $detalhe->obrigacoesAvulsasPagas);
        self::assertSame(155000, $detalhe->totalPagoDasAvulsas, 'Σ do recebido');

        // A prova de que a régua é o recebido: o cobrado é OUTRO número neste cenário (150000).
        $cobrado = array_sum(array_map(
            static fn ($o): int => $o->valorAtual,
            $detalhe->obrigacoesAvulsasPagas,
        ));
        self::assertSame(150000, $cobrado, 'Σ do cobrado: 120000 + 30000');
        self::assertGreaterThan(
            $cobrado,
            $detalhe->totalPagoDasAvulsas,
            'o cenário precisa fazer o recebido divergir do cobrado, senão o assert acima não discrimina',
        );

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'R$ 1.550,00',
            $crawler->filter('#secao-ja-pago [data-meta="total-ja-pago"]')->text(),
        );
    }

    #[TestDox('R5: a partição é ADICIONAL — a união e os totais do cabeçalho não se movem')]
    public function testUniaoETotaisDoCabecalhoPermanecemIntactos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Carteira que COBRA honorários: sem ela a hidratação de encargos zera o honorário de toda
        // obrigação e os asserts abaixo passariam somando 0 = 0 + 0, sem discriminar nada.
        [, $caso] = $this->semearGrafo($tenant, [], self::carteiraQueCobraHonorarios());

        // Alocado 120000 = exigível (100000 + 20% de honorário): quitar exige cobrir o honorário.
        $this->obrigacaoQuitada($tenant, $caso, 'Taxa 01/2026', 100000, new \DateTimeImmutable('2026-01-05'), 120000);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 07/2026', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-5 days'),
        ]);

        $detalhe = static::getContainer()->get(MontarDetalheCasoUseCase::class)->executar($caso);

        // A união continua com as DUAS — é dela que a aba Honorários e os cards saem.
        self::assertCount(2, $detalhe->obrigacoesAvulsas);
        self::assertCount(1, $detalhe->obrigacoesAvulsasEmAberto);
        self::assertCount(1, $detalhe->obrigacoesAvulsasPagas);

        $honorariosPagas = array_sum(array_map(
            static fn ($o): int => $o->honorarios,
            $detalhe->obrigacoesAvulsasPagas,
        ));
        $honorariosEmAbertoNaParticao = array_sum(array_map(
            static fn ($o): int => $o->honorarios,
            $detalhe->obrigacoesAvulsasEmAberto,
        ));

        // O cenário só discrimina se a PAGA carrega honorário — senão a soma das duas seria igual à de
        // uma só, e trocar a união pela partição passaria despercebido.
        self::assertGreaterThan(0, $honorariosPagas, 'a obrigação paga precisa ter honorário no cenário');

        // O rodapé da aba Honorários soma tudo que ela LISTA — as duas. Se a partição tivesse
        // substituído a união, ele cairia para o valor da em aberto sozinha.
        self::assertSame(
            $honorariosPagas + $honorariosEmAbertoNaParticao,
            $detalhe->honorariosDasObrigacoes,
            'o rodapé da aba Honorários continua somando as DUAS',
        );
        self::assertSame($honorariosEmAbertoNaParticao, $detalhe->honorariosEmAberto);
        self::assertSame(1, $detalhe->obrigacoesEmAbertoQtd);
        // O card `Principal` do cabeçalho: só a vencida em aberto. A paga nunca entrou nele.
        self::assertSame(120000, $detalhe->totalPrincipalVencido);
    }

    #[TestDox('R5: em CLI (filtro global desligado), o "Pago em" ignora alocação de outro escritório')]
    public function testDataDoPagamentoRespeitaOTenantSemOFiltroGlobal(): void
    {
        self::bootKernel();
        $tenant = $this->tenantAvulso();
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = $this->obrigacaoQuitada($tenant, $caso, 'Taxa 01/2026', 100000, new \DateTimeImmutable('2026-01-05'));

        // ⚠️ Duas armadilhas medidas ao escrever este teste, ambas por injeção de defeito:
        //
        // 1. "outro escritório com outro caso" NÃO prova nada: o mapa filtra por `o.caso IN (:casos)`,
        //    e um caso alheio jamais entraria nele. O que o filtro de tenant defende é o dado CRUZADO —
        //    alocação de outro escritório apontando para uma obrigação DESTE caso.
        //
        // 2. Pelo caminho HTTP o teste TAMBÉM passa sem o filtro explícito, porque o `TenantFilter`
        //    global (habilitado por request pelo `TenantFilterListener`) já injeta `tenant_id` em toda
        //    entidade `TenantAware`. Ele fica DESLIGADO em CLI — e é em CLI que o importador de receitas
        //    roda. Por isso este teste NÃO faz request: sem o filtro global, o `andWhere` da query é a
        //    única defesa, e é exatamente ele que está sob prova aqui.
        //
        // A data alheia é a mais RECENTE de propósito: o agregado é um `MAX`, então o vazamento não
        // estoura em lugar nenhum — ele troca a data em silêncio.
        $outro = $this->tenantAvulso();
        [, $casoOutro] = $this->semearGrafo($outro);
        $pagamentoAlheio = PagamentoFactory::createOne([
            'tenant' => $outro, 'caso' => $casoOutro, 'data' => new \DateTimeImmutable('2026-06-30'),
            'valorDivida' => 100000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $outro, 'pagamento' => $pagamentoAlheio, 'obrigacao' => $obrigacao, 'valor' => 100000,
        ]);

        self::assertFalse(
            static::getContainer()->get(EntityManagerInterface::class)->getFilters()->isEnabled('tenant'),
            'o filtro global tem de estar DESLIGADO aqui, senão o teste não prova o filtro da query',
        );

        $mapa = static::getContainer()->get(AlocacaoPagamentoRepository::class)
            ->ultimoPagamentoPorObrigacaoDosCasos([$caso->getId()], $tenant);

        self::assertSame(
            '2026-01-05',
            $mapa[$obrigacao->getId()]->format('Y-m-d'),
            'a data do pagamento alheio não pode substituir a deste escritório',
        );
    }

    #[TestDox('R5: obrigação de exigível ZERO cai na seção e não imprime data inventada')]
    public function testObrigacaoDeExigivelZeroApareceSemQuebrarALinha(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Deixou de ser hipótese com o importador de receitas: medido em 03/08, 10 recebimentos da
        // TOP LIFE I são SÓ honorário (R$ 2.618,18). A obrigação que R1 cria para eles nasce com
        // `valorOriginal = 0`, satisfaz `quitada()` por 0 ≥ 0 e a alocação que a acompanha vale
        // R$ 0,00 — este é o formato exato dessa linha na tela.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 04/2026 (NN 9999)', 'valorOriginal' => 0, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-04-10'),
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        $pagas = $crawler->filter('#secao-ja-pago .jp-pagas-linha');
        self::assertCount(1, $pagas, 'exigível zero satisfaz quitada() e cai no histórico');
        self::assertCount(0, $crawler->filter('#secao-divida .jp-obr'), 'e não fica na fila de cobrança');

        // Sem alocação nenhuma, não há data. O travessão é o certo — a alternativa silenciosa seria
        // imprimir 01/01/1970, que é o que um `?->format()` mal guardado produz.
        self::assertSame('—', trim($pagas->filter('.jp-pagas-data')->text()));
        self::assertStringContainsString('R$ 0,00', $pagas->filter('.jp-pagas-valor')->text());
    }

    /**
     * Obrigação quitada por pagamento na data informada — o formato que a importação de receitas cria
     * (uma alocação só, cobrindo o exigível inteiro).
     *
     * A alocação é do EXIGÍVEL, e desde a spec `cobranca-honorario-no-total.md` o exigível INCLUI o
     * honorário — por isso `$alocado` é explícito: alocar só o principal deixaria a linha em aberto,
     * que é exatamente o comportamento novo (quem paga principal e não paga honorário não quitou).
     */
    private function obrigacaoQuitada(
        Tenant $tenant,
        CasoCobranca $caso,
        string $descricao,
        int $valor,
        \DateTimeImmutable $pagoEm,
        ?int $alocado = null,
    ): Obrigacao {
        $alocado ??= $valor;
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => $descricao, 'valorOriginal' => $valor, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => $pagoEm->modify('-5 days'),
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'data' => $pagoEm,
            'valorDivida' => $alocado, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => $alocado,
        ]);

        return $obrigacao;
    }

    /** @return array<string, mixed> */
    private static function carteiraQueCobraHonorarios(): array
    {
        return [
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
            'baseHonorarios' => BaseEncargo::Principal,
            'carenciaHonorariosDias' => 0,
        ];
    }
}
