<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\Importacao\AcordoDoRelatorio;
use App\Cobranca\Service\Importacao\ReceitaImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacaoReceitas;
use App\Cobranca\Service\Importacao\ResultadoLeituraReceitas;
use App\Cobranca\UseCase\ImportarReceitasUseCase;
use App\Entity\Auth\User;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * ETAPA 3 — o recebimento nasce como PARCELA DE ACORDO
 * (spec `docs/specs/cobranca-receitas-parcela-de-acordo.md`).
 *
 * A coluna J do relatório diz "Acordo 348 - Parc. 1/40": o NN não é um boleto avulso, é a parcela de um
 * acordo, e as várias linhas do mesmo NN são a COMPOSIÇÃO dela — as dívidas originais que o acordo
 * consolidou naquela parcela. Por isso a parcela 1 pode ser 100% honorário e a 8 quase toda taxa.
 *
 * 🔑 Os três testes de dinheiro (`valorOriginal`, honorário materializado e alocação) são SEPARADOS de
 * propósito, cada um com o cenário mínimo. Eles guardam três metades da mesma conta, e num cenário
 * único as defesas ficariam em série: injetar o defeito de uma derrubaria o assert da outra, e a
 * "prova por injeção" mediria a coisa errada — foi exatamente assim que ela falhou na etapa 2.
 */
#[CoversClass(ImportarReceitasUseCase::class)]
final class ImportarReceitasAcordoTest extends CobrancaWebTestCase
{
    #[TestDox('Coluna J vazia continua avulsa: sem acordo, honorário materializado, valor só do principal')]
    public function testColunaJVaziaContinuaAvulsa(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        // O caminho de 1.891 dos 2.078 recebimentos. Se a etapa 3 mexer nele, mexeu no que já estava
        // provado e conferido ao centavo contra a contabilidade.
        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita('AV-01', '9001', divida: 20000, honorarios: 3000, acordo: null)]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        $obrigacao = $this->obrigacaoPorNn('9001');

        self::assertNull($obrigacao->getAcordoOrigem(), 'sem coluna J não há acordo');
        self::assertSame(20000, $obrigacao->getValorOriginal(), 'valorOriginal continua sendo só o principal');
        self::assertSame(3000, $obrigacao->getHonorarios(), 'e o honorário continua materializado à parte');
        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cobranca_acordo WHERE tenant_id = ?', [$tenant->getId()]),
            'nenhum acordo nasce do caminho avulso',
        );
    }

    #[TestDox('🔑 Parcela de acordo: o acordo é CRIADO e a obrigação nasce ligada a ele')]
    public function testParcelaNasceLigadaAoAcordoCriado(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                $this->receita('AC-01', '9010', divida: 24211, acordo: new AcordoDoRelatorio(348, 1, 40)),
            ]),
            $tenant,
            $usuario,
        );

        self::assertSame(1, $resultado->acordosCriados);
        self::assertSame(['9010'], $resultado->parcelasDeAcordo);

        $this->em()->clear();
        $acordo = $this->acordoPorNumeroExterno(348, $tenant);

        self::assertNotNull($acordo, 'decisão A1: parcela paga ⇒ o acordo tem de ser criado');
        self::assertSame(40, $acordo->getNumeroParcelasTotal());
        self::assertSame(StatusAcordo::Ativo, $acordo->getStatus(), 'decisão A2: nasce Ativo');
        self::assertNull($acordo->getValorTotalNegociado(), 'com 40 parcelas o total negociado não vem da fonte');

        $obrigacao = $this->obrigacaoPorNn('9010');
        self::assertNotNull($obrigacao->getAcordoOrigem(), 'a obrigação nasce como PARCELA, não avulsa');
        self::assertSame($acordo->getId(), $obrigacao->getAcordoOrigem()->getId());
        self::assertStringContainsString('Acordo 348 - Parc. 1/40', $obrigacao->getDescricao());
    }

    #[TestDox('Acordo de UMA parcela grava o valor total negociado')]
    public function testAcordoDeUmaParcelaGravaValorTotal(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                // 300,00 de taxa + 50,00 de honorário; 12,00 de juros são atraso, não valor negociado.
                $this->receita('AC-02', '9020', divida: 30000, juros: 1200, honorarios: 5000, acordo: new AcordoDoRelatorio(9, 1, 1)),
            ]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        $acordo = $this->acordoPorNumeroExterno(9, $tenant);
        self::assertNotNull($acordo);
        self::assertSame(35000, $acordo->getValorTotalNegociado(), 'principal + honorário embutido, SEM os juros de atraso');
    }

    #[TestDox('🔑 Acordo já existente na MESMA carteira é reusado, não duplicado')]
    public function testAcordoExistenteEhReusado(): void
    {
        [$carteira, $caso, $tenant, $usuario] = $this->cenario();
        $existente = $this->criarAcordo($caso, $tenant, 348, 40, StatusAcordo::Ativo);
        $idExistente = (int) $existente->getId();

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita('AC-03', '9030', divida: 10000, acordo: new AcordoDoRelatorio(348, 5, 40))]),
            $tenant,
            $usuario,
        );

        self::assertSame(0, $resultado->acordosCriados, 'o acordo já existia');

        $this->em()->clear();
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM cobranca_acordo WHERE tenant_id = ? AND numero_externo = 348',
                [$tenant->getId()],
            ),
            'um acordo 348 no banco, não dois',
        );
        self::assertSame($idExistente, (int) $this->obrigacaoPorNn('9030')->getAcordoOrigem()?->getId());
    }

    #[TestDox('🔑 Mesmo número de acordo em OUTRA carteira do mesmo tenant é outro acordo')]
    public function testMesmoNumeroEmOutraCarteiraEhOutroAcordo(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();
        // Medido em 03/08: os relatórios de Acordos das duas carteiras têm ambos uma aba "31" e uma
        // "32", e são acordos DIFERENTES. O índice `(tenant_id, numero_externo)` não é único e não
        // pegaria isso — quem separa é o filtro por carteira da busca.
        [, $casoOutraCarteira] = $this->semearGrafo($tenant);
        $this->criarAcordo($casoOutraCarteira, $tenant, 31, 3, StatusAcordo::Ativo);

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita('AC-04', '9040', divida: 10000, acordo: new AcordoDoRelatorio(31, 1, 3))]),
            $tenant,
            $usuario,
        );

        self::assertSame(1, $resultado->acordosCriados, 'o 31 da outra carteira não é este 31');

        $this->em()->clear();
        self::assertSame(
            2,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM cobranca_acordo WHERE tenant_id = ? AND numero_externo = 31',
                [$tenant->getId()],
            ),
            'dois acordos de número 31 no tenant, um por carteira',
        );
    }

    #[TestDox('🔑 Duas parcelas do mesmo acordo criam UM acordo — e a prévia diz o mesmo que a confirmação')]
    public function testDuasParcelasDoMesmoAcordoCriamUmSoAcordo(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();
        $carteiraId = (int) $carteira->getId();

        // 🔑 O cenário que pega a prévia sem ESTADO — o defeito que já apareceu DUAS vezes nesta frente.
        // A prévia não grava, então o banco responde "acordo não existe" nas DUAS consultas: sem memória
        // da execução ela prometeria 2 acordos criados onde a confirmação cria 1.
        $leitura = $this->leitura([
            $this->receita('AC-05', '9050', divida: 10000, acordo: new AcordoDoRelatorio(377, 1, 3)),
            $this->receita('AC-05', '9051', divida: 10000, acordo: new AcordoDoRelatorio(377, 2, 3)),
        ]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);
        $previa = $sut->prever($carteiraId, $leitura, $tenant);
        $confirmacao = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame(1, $previa->acordosCriados, 'duas parcelas, UM acordo');
        self::assertSame($this->achatar($previa), $this->achatar($confirmacao), 'prévia e confirmação em TODOS os campos');

        $this->em()->clear();
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM cobranca_acordo WHERE tenant_id = ? AND numero_externo = 377',
                [$tenant->getId()],
            ),
        );
    }

    #[TestDox('🔑 valorOriginal da parcela = principal + honorário embutido (juros/multa ficam de fora)')]
    public function testValorOriginalDaParcelaSomaOHonorario(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        // Cenário MÍNIMO e isolado: só este assert. O honorário embutido é o que faz a Parc. 1/40 do
        // Acordo 348 valer R$ 242,11 em vez dos R$ 0,00 que a etapa 2 gravaria.
        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                $this->receita('AC-06', '9060', divida: 0, juros: 700, multa: 300, honorarios: 24211, acordo: new AcordoDoRelatorio(348, 1, 40)),
            ]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        self::assertSame(
            24211,
            $this->obrigacaoPorNn('9060')->getValorOriginal(),
            'a parcela só de honorário vale o que a planilha recebeu, não R$ 0,00',
        );
    }

    #[TestDox('🔑 A parcela NÃO materializa honorário — ele já está dentro do valorOriginal')]
    public function testParcelaNaoMaterializaHonorario(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        // Isolado do assert acima: se o honorário entrasse nos DOIS lugares, `totalComHonorarios()`
        // o contaria duas vezes e a parcela nasceria devendo.
        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                $this->receita('AC-07', '9070', divida: 20000, honorarios: 5000, acordo: new AcordoDoRelatorio(350, 1, 2)),
            ]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        self::assertSame(0, $this->obrigacaoPorNn('9070')->getHonorarios(), 'honorário materializado tem de ser ZERO na parcela');
    }

    #[TestDox('🔑 A parcela nasce QUITADA: o alocado fecha com o exigível e o saldo do caso não se move')]
    public function testParcelaNasceQuitada(): void
    {
        [$carteira, $caso, $tenant, $usuario] = $this->cenario();

        // Uma dívida real em aberto, que tem de sobreviver intacta.
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 30000, 'encargosReconhecidos' => 0]);
        $casoId = (int) $caso->getId();
        $identificacao = $caso->getObjeto()->getIdentificacao();

        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                $this->receita($identificacao, '9080', divida: 20000, juros: 700, multa: 300, honorarios: 5000, acordo: new AcordoDoRelatorio(351, 1, 2)),
            ]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        $parcela = $this->obrigacaoPorNn('9080');

        // 250,00 de principal negociado (200 + 50 de honorário) + 10,00 de atraso.
        self::assertSame(26000, $parcela->valorExigivel(), 'exigível = principal negociado + juros e multa do atraso');
        self::assertSame(
            26000,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(a.valor), 0) FROM cobranca_alocacao_pagamento a
                   JOIN cobranca_obrigacao o ON o.id = a.obrigacao_id
                  WHERE o.referencia_externa = ? AND a.tenant_id = ?',
                ['9080', $tenant->getId()],
            ),
            'a alocação leva o BRUTO — é o que fecha com o exigível da parcela',
        );

        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(
            30000,
            $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)),
            'a parcela entra e sai da conta no mesmo valor: a dívida em aberto não muda',
        );
    }

    #[TestDox('A parcela grava honorário 0 na configuração: reaberta, não volta a acumular honorário')]
    public function testParcelaGravaOverrideDeHonorarioZero(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita('AC-09', '9090', divida: 20000, acordo: new AcordoDoRelatorio(352, 1, 2))]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        // Vai ao BANCO e não ao getter: o `honorariosBp = 0` da etapa 2 era inerte justamente porque o
        // valor era descartado antes de chegar à entidade. Ler a coluna prova que ele chegou.
        //
        // ⚠️ E o valor é lido CRU, sem cast. A primeira versão deste assert fazia `(int) fetchOne(...)`
        // e comparava com 0 — e `(int) null` também é 0, então ele passava com a coluna NULA, isto é,
        // com o override NÃO gravado. A prova por injeção pegou: trocar `'percent'` por `'herda'`
        // deixava a suíte verde. É a mesma armadilha de assert vacuoso da etapa 2, em roupa nova.
        $bp = $this->em()->getConnection()->fetchOne(
            'SELECT taxa_honorarios_bp FROM cobranca_obrigacao WHERE referencia_externa = ? AND tenant_id = ?',
            ['9090', $tenant->getId()],
        );

        self::assertNotNull($bp, 'o override tem de estar GRAVADO, não herdado (NULL = herda a cascata da carteira)');
        self::assertSame(0, (int) $bp, 'acordo não cobra honorário sobre honorário');
    }

    #[TestDox('🔑 Todas as parcelas pagas ⇒ Cumprido; faltando uma ⇒ Ativo (decisão A2)')]
    public function testStatusCumpridoSoQuandoTodasAsParcelasEstaoPagas(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                // Acordo 400: 2 de 2 pagas → Cumprido.
                $this->receita('AC-10', '9100', divida: 10000, acordo: new AcordoDoRelatorio(400, 1, 2)),
                $this->receita('AC-10', '9101', divida: 10000, acordo: new AcordoDoRelatorio(400, 2, 2)),
                // Acordo 401: 1 de 3 → continua Ativo.
                $this->receita('AC-11', '9110', divida: 10000, acordo: new AcordoDoRelatorio(401, 1, 3)),
            ]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        self::assertSame(
            StatusAcordo::Cumprido,
            $this->acordoPorNumeroExterno(400, $tenant)?->getStatus(),
            'acordo com as 2 de 2 parcelas pagas termina CUMPRIDO',
        );
        self::assertSame(
            StatusAcordo::Ativo,
            $this->acordoPorNumeroExterno(401, $tenant)?->getStatus(),
            'acordo com 1 de 3 continua ATIVO — marcar cumprido aqui seria subcobrança silenciosa',
        );
    }

    #[TestDox('A mesma parcela repetida NÃO empurra o acordo para Cumprido')]
    public function testParcelaRepetidaNaoCompletaOAcordo(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        // Medido: hoje nenhuma parcela tem dois NNs. Mas a régua do `Cumprido` não pode depender de uma
        // propriedade da fonte de hoje — contar ocorrências em vez de índices distintos marcaria este
        // acordo como pago sem que a parcela 2 tenha entrado.
        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                $this->receita('AC-12', '9120', divida: 10000, acordo: new AcordoDoRelatorio(402, 1, 2)),
                $this->receita('AC-12', '9121', divida: 10000, acordo: new AcordoDoRelatorio(402, 1, 2)),
            ]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        self::assertSame(
            StatusAcordo::Ativo,
            $this->acordoPorNumeroExterno(402, $tenant)?->getStatus(),
            'a parcela 1 duas vezes não é "as duas parcelas pagas" — contar ocorrências marcaria CUMPRIDO indevidamente',
        );
    }

    #[TestDox('Acordo incompleto sai no resultado com quantas parcelas faltam (decisão B1)')]
    public function testAcordoIncompletoEhReportado(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([
                $this->receita('AC-13', '9130', divida: 10000, acordo: new AcordoDoRelatorio(212, 1, 20)),
                // Um acordo COMPLETO junto, senão o assert passaria com o filtro invertido.
                $this->receita('AC-14', '9140', divida: 10000, acordo: new AcordoDoRelatorio(403, 1, 1)),
            ]),
            $tenant,
            $usuario,
        );

        self::assertSame([['numero' => 212, 'pagas' => 1, 'total' => 20]], $resultado->acordosIncompletos);
        self::assertSame(1, $resultado->acordosCumpridos, 'o 403 fechou; o 212 não');
    }

    #[TestDox('🔑 D6: acordo ROMPIDO volta a Ativo pela importação, com registro no histórico')]
    public function testAcordoRompidoVoltaAAtivo(): void
    {
        [$carteira, $caso, $tenant, $usuario] = $this->cenario();
        $acordo = $this->criarAcordo($caso, $tenant, 500, 2, StatusAcordo::Rompido);
        $acordo->setMotivoRompimento('inadimplência de 3 parcelas');
        $this->em()->flush();
        $casoId = (int) $caso->getId();
        // A parcela pousa na MESMA unidade do acordo — medido: nenhum acordo cruza unidade.
        $identificacao = $caso->getObjeto()->getIdentificacao();

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita($identificacao, '9150', divida: 10000, acordo: new AcordoDoRelatorio(500, 1, 2))]),
            $tenant,
            $usuario,
        );

        self::assertSame(1, $resultado->acordosReativados, 'o importe é a verdade absoluta');

        $this->em()->clear();
        $recarregado = $this->acordoPorNumeroExterno(500, $tenant);
        self::assertSame(StatusAcordo::Ativo, $recarregado?->getStatus(), 'o acordo rompido tem de voltar a Ativo no BANCO');
        self::assertNull($recarregado?->getMotivoRompimento(), 'o motivo antigo descreve um estado que não vale mais');

        // Pelo ORM e não por SQL cru: a coluna do vínculo tem nome diferente no dev e no banco de
        // teste (`objeto_id` × `caso_id`), então um SELECT escrito à mão passa num e quebra no outro.
        $eventos = $this->em()->getRepository(EventoHistorico::class)->findBy([
            'caso' => $this->em()->find(CasoCobranca::class, $casoId),
            'tipo' => TipoEventoHistorico::AcordoEditado,
        ]);
        self::assertCount(1, $eventos, 'a reativação fica no histórico do caso');
        self::assertStringContainsString('reativado pela importação', $eventos[0]->getDescricao());
        self::assertStringContainsString('Rompido', $eventos[0]->getDescricao(), 'e diz de que estado veio');
    }

    #[TestDox('D6 não toca acordo que já está vigente')]
    public function testAcordoVigenteNaoEhReativado(): void
    {
        [$carteira, $caso, $tenant, $usuario] = $this->cenario();
        $this->criarAcordo($caso, $tenant, 501, 2, StatusAcordo::Ativo);

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita('AC-16', '9160', divida: 10000, acordo: new AcordoDoRelatorio(501, 1, 2))]),
            $tenant,
            $usuario,
        );

        self::assertSame(0, $resultado->acordosReativados, 'nada a reativar num acordo já vigente');
        self::assertSame([], $resultado->reativacoesComDinheiroParado);
    }

    #[TestDox('🔑 D6: reativação que tira do exigível uma original COM pagamento é REPORTADA')]
    public function testReativacaoComDinheiroAlocadoEhReportada(): void
    {
        [$carteira, $caso, $tenant, $usuario] = $this->cenario();
        $acordo = $this->criarAcordo($caso, $tenant, 502, 2, StatusAcordo::Rompido);

        // A cadeia que a spec-cancelar §3.2 manda vigiar: acordo rompido → a original volta ao saldo →
        // alguém recebe nela → a planilha traz o acordo → reativação → a original sai do exigível e o
        // dinheiro PARA de abater. É o pior erro possível aqui: cobrar o que já foi pago.
        $original = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 01/2025', 'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'referenciaExterna' => '7000',
        ])->_real();
        $original->setAcordoSubstituto($acordo);
        $this->em()->flush();
        $this->alocarPagamentoManual($original, $caso, $tenant, $usuario, 15000);

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita('AC-17', '9170', divida: 10000, acordo: new AcordoDoRelatorio(502, 1, 2))]),
            $tenant,
            $usuario,
        );

        self::assertCount(1, $resultado->reativacoesComDinheiroParado, 'o dinheiro que para de abater tem de ser listado');
        self::assertStringContainsString('NN 7000', $resultado->reativacoesComDinheiroParado[0]);
        self::assertStringContainsString('150,00', $resultado->reativacoesComDinheiroParado[0]);
    }

    #[TestDox('D6: reativação de acordo cujas originais NÃO receberam nada não reporta nada')]
    public function testReativacaoSemDinheiroAlocadoNaoReportaNada(): void
    {
        [$carteira, $caso, $tenant, $usuario] = $this->cenario();
        $acordo = $this->criarAcordo($caso, $tenant, 503, 2, StatusAcordo::Rompido);

        // O par negativo do teste acima: a original existe e é substituída, mas ninguém pagou nela.
        // Sem este cenário, um aviso disparado por QUALQUER original passaria despercebido.
        $original = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 02/2025', 'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'referenciaExterna' => '7001',
        ])->_real();
        $original->setAcordoSubstituto($acordo);
        $this->em()->flush();

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita('AC-18', '9180', divida: 10000, acordo: new AcordoDoRelatorio(503, 1, 2))]),
            $tenant,
            $usuario,
        );

        self::assertSame(1, $resultado->acordosReativados, 'reativou');
        self::assertSame([], $resultado->reativacoesComDinheiroParado, 'mas não há dinheiro parado para avisar');
    }

    #[TestDox('🔑 Reimportar não cria acordo de novo nem duplica o vínculo')]
    public function testReimportarNaoCriaAcordoDeNovo(): void
    {
        [$carteira, , $tenant, $usuario] = $this->cenario();
        $carteiraId = (int) $carteira->getId();
        $leitura = $this->leitura([$this->receita('AC-19', '9190', divida: 10000, acordo: new AcordoDoRelatorio(504, 1, 1))]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);
        $primeira = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);
        self::assertSame(1, $primeira->acordosCriados);

        $this->em()->clear();
        $segunda = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame(0, $segunda->acordosCriados, 'a segunda rodada não cria acordo nenhum');
        self::assertSame(['9190'], $segunda->jaImportados);

        $this->em()->clear();
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM cobranca_acordo WHERE tenant_id = ? AND numero_externo = 504',
                [$tenant->getId()],
            ),
        );
    }

    #[TestDox('Obrigação que já existia AVULSA passa a apontar para o acordo na reimportação')]
    public function testObrigacaoAvulsaPreexistenteGanhaOVinculo(): void
    {
        [$carteira, $caso, $tenant, $usuario] = $this->cenario();
        $identificacao = $caso->getObjeto()->getIdentificacao();

        // O caso real do acervo: obrigação criada por uma importação ANTERIOR a esta etapa, sem
        // vínculo. Ela não pode ficar órfã só porque nasceu antes.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 05/2026', 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
            'referenciaExterna' => '9200', 'competencia' => '05/2026',
        ]);

        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita($identificacao, '9200', divida: 10000, acordo: new AcordoDoRelatorio(505, 1, 1))]),
            $tenant,
            $usuario,
        );

        $this->em()->clear();
        $obrigacao = $this->obrigacaoPorNn('9200');
        self::assertNotNull($obrigacao->getAcordoOrigem(), 'a preexistente ganha o vínculo');
        self::assertSame(505, $obrigacao->getAcordoOrigem()?->getNumeroExterno());
        self::assertSame(10000, $obrigacao->getValorOriginal(), 'mas o valorOriginal dela NÃO é reescrito (invariável 20)');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: \App\Cobranca\Entity\Carteira, 1: CasoCobranca, 2: \App\Entity\Tenant\Tenant, 3: User} */
    private function cenario(): array
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $usuario = $this->em()->getRepository(User::class)->findAll()[0];

        return [$carteira, $caso, $tenant, $usuario];
    }

    private function criarAcordo(CasoCobranca $caso, \App\Entity\Tenant\Tenant $tenant, int $numeroExterno, int $total, StatusAcordo $status): Acordo
    {
        $acordo = new Acordo();
        $acordo->setTenant($tenant);
        $acordo->setCaso($caso);
        $acordo->setStatus($status);
        $acordo->setDataAcordo(new \DateTimeImmutable('2026-01-10'));
        $acordo->setNumeroExterno($numeroExterno);
        $acordo->setNumeroParcelasTotal($total);
        $this->em()->persist($acordo);
        $this->em()->flush();

        return $acordo;
    }

    private function alocarPagamentoManual(Obrigacao $obrigacao, CasoCobranca $caso, \App\Entity\Tenant\Tenant $tenant, User $user, int $valor): void
    {
        $pagamento = new \App\Cobranca\Entity\Pagamento();
        $pagamento->setTenant($tenant);
        $pagamento->setCaso($caso);
        $pagamento->setData(new \DateTimeImmutable('2026-02-01'));
        $pagamento->setValorDivida($valor);
        $pagamento->setValorEncargos(0);
        $pagamento->setValorHonorarios(0);
        $pagamento->setCriadoPor($user);

        $alocacao = new \App\Cobranca\Entity\AlocacaoPagamento();
        $alocacao->setTenant($tenant);
        $alocacao->setObrigacao($obrigacao);
        $alocacao->setValor($valor);
        $pagamento->adicionarAlocacao($alocacao);

        $this->em()->persist($pagamento);
        $this->em()->flush();
    }

    private function obrigacaoPorNn(string $nn): Obrigacao
    {
        $obrigacao = $this->em()->getRepository(Obrigacao::class)->findOneBy(['referenciaExterna' => $nn]);
        self::assertNotNull($obrigacao, sprintf('obrigação do NN %s não foi encontrada', $nn));

        return $obrigacao;
    }

    private function acordoPorNumeroExterno(int $numero, \App\Entity\Tenant\Tenant $tenant): ?Acordo
    {
        return $this->em()->getRepository(Acordo::class)->findOneBy(['numeroExterno' => $numero, 'tenant' => $tenant]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<ReceitaImportavel> $receitas */
    private function leitura(array $receitas): ResultadoLeituraReceitas
    {
        return new ResultadoLeituraReceitas($receitas, [], 0, 0);
    }

    private function receita(
        string $unidade,
        string $nn,
        int $divida,
        int $juros = 0,
        int $multa = 0,
        int $honorarios = 0,
        ?AcordoDoRelatorio $acordo = null,
    ): ReceitaImportavel {
        return new ReceitaImportavel(
            nn: $nn,
            objetoIdentificacao: $unidade,
            unidadeMetadata: null,
            sacadoNome: 'Sacado ' . $nn,
            competencia: '05/2026',
            vencimento: new \DateTimeImmutable('2026-05-10'),
            recebimento: new \DateTimeImmutable('2026-05-20'),
            valorDividaCentavos: $divida,
            valorJurosCentavos: $juros,
            valorMultaCentavos: $multa,
            valorHonorariosCentavos: $honorarios,
            acordo: $acordo,
            linhas: [],
        );
    }

    /**
     * Mesma reflexão do `ImportarReceitasFluxoTest`: compara TODOS os campos, para que um campo novo
     * entre na comparação sem ninguém lembrar.
     *
     * @return array<string, mixed>
     */
    private function achatar(ResultadoImportacaoReceitas $r): array
    {
        $achatado = [];
        foreach ((new \ReflectionClass($r))->getProperties(\ReflectionProperty::IS_PUBLIC) as $propriedade) {
            $nome = $propriedade->getName();
            $valor = $propriedade->getValue($r);
            $achatado[$nome] = $nome === 'rejeitadas' ? count((array) $valor) : $valor;
        }
        ksort($achatado);

        return $achatado;
    }
}
