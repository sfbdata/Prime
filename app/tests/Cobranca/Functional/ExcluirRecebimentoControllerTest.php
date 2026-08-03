<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\PagamentoController;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Exclusão de recebimento (spec `cobranca-excluir-recebimento.md`) contra o banco real — é onde as
 * provas que o teste unitário NÃO alcança se medem: o saldo derivado por query agregada, o cascade das
 * alocações e o fecho do portão do cancelamento de acordo (§D4 de `cobranca-cancelar-acordo.md`).
 *
 * O grafo usa a carteira SemPercentual (default de `semearGrafo`) → honorários 0 → parte-dívida = valor
 * pago, e obrigação com `encargosReconhecidos` 0 → exigível = valorOriginal. Assim os números do teste
 * são os da tela, sem o motor de encargos no meio.
 */
#[CoversClass(PagamentoController::class)]
final class ExcluirRecebimentoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Excluir recebimento: o saldo sobe exatamente o valor apagado e a obrigação volta a VIVA')]
    public function testExcluirDevolveOValorAoSaldoEReabreAObrigacao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        // 1) Recebe 200,00 (AUTO/FIFO) — quita a obrigação e zera o saldo do caso.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '200,00',
                '_token' => $this->tokenDoFormulario($crawler, 'registrar_pagamento'),
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');

        $this->em()->clear();
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(0, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'pré-condição: caso quitado');
        self::assertTrue($this->em()->find(Obrigacao::class, $obrigacaoId)->estaLiquidada(), 'pré-condição: obrigação liquidada');

        // 2) O botão Excluir existe na linha do recebimento e traz o token CSRF por pagamento.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $pagamentoId = (int) $this->pagamentosDoCaso($casoId)[0]->getId();
        $botao = $crawler->filter('button[data-acao-url="/cobrancas/pagamentos/' . $pagamentoId . '/excluir"]');
        self::assertCount(1, $botao, 'sem o botão na linha, a ação não é alcançável pelo gestor');

        // 3) Apaga.
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/excluir', [
            '_token' => $botao->attr('data-token'),
            'motivo' => 'Recebimento lançado em duplicidade',
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');

        $this->em()->clear();
        $calc = static::getContainer()->get(CalculadoraSaldo::class);

        // P1: o saldo sobe EXATAMENTE o valor apagado — nem mais, nem menos.
        self::assertSame(20000, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)));

        // P2: a obrigação volta a VIVA e descongela (os juros voltam a correr).
        $fresh = $this->em()->find(Obrigacao::class, $obrigacaoId);
        self::assertFalse($fresh->estaLiquidada(), 'dívida quitada com o pagamento apagado seria dinheiro que não existe abatendo dívida que existe');
        self::assertNull($fresh->getLiquidadaEm());
        self::assertFalse($fresh->encargosCongelados());

        // P10: o pagamento e as alocações somem — nenhuma alocação órfã.
        self::assertNull($this->em()->find(Pagamento::class, $pagamentoId), 'o pagamento foi apagado de verdade');
        self::assertSame(
            0,
            (int) $this->em()->createQuery(
                'SELECT COUNT(a.id) FROM ' . AlocacaoPagamento::class . ' a WHERE a.pagamento = :p',
            )->setParameter('p', $pagamentoId)->getSingleScalarResult(),
            'cascade + orphanRemoval têm de levar as alocações junto',
        );

        // P11: o evento é autocontido — o pagamento não existe mais para ser consultado.
        $eventos = static::getContainer()->get(EventoHistoricoRepository::class)
            ->findBy(['caso' => $this->em()->find(CasoCobranca::class, $casoId), 'tipo' => TipoEventoHistorico::PagamentoExcluido]);
        self::assertCount(1, $eventos);
        $dados = $eventos[0]->getDados();
        self::assertSame(20000, $dados['valorTotal']);
        self::assertSame('2026-05-10', $dados['data']);
        self::assertSame('Recebimento lançado em duplicidade', $dados['motivo']);
        self::assertSame([['obrigacaoId' => $obrigacaoId, 'descricao' => $fresh->getDescricao(), 'valor' => 20000]], $dados['alocacoes']);
    }

    #[TestDox('🔑 Depois de excluir o recebimento, cancelar o acordo PASSA — é o fecho do beco sem saída')]
    public function testExcluirRecebimentoDestravaOCancelamentoDoAcordo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'acordoOrigem' => $acordo,
            'valorOriginal' => 5000, 'encargosReconhecidos' => 0, 'descricao' => 'Parcela 1/10 do acordo',
        ])->_real();
        $acordoId = (int) $acordo->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        // Um pagamento alocado NA PARCELA — exatamente o que a guarda §D4 recusa.
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 5000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $this->alocar($tenant, $pagamento, $parcela, 5000);
        $pagamentoId = (int) $pagamento->getId();

        // 1) Cancelar AGORA é recusado, e a mensagem aponta o caminho (não é mais um beco sem saída).
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'Erro de lançamento', '_token' => $this->tokenDoFormulario($crawler, 'cancelar_acordo')],
        ]);
        $this->em()->clear();
        self::assertSame(
            StatusAcordo::Ativo,
            $this->em()->find(Acordo::class, $acordoId)->getStatus(),
            'pré-condição: com parcela paga o acordo NÃO cancela',
        );

        // 2) Apaga o recebimento.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $botao = $crawler->filter('button[data-acao-url="/cobrancas/pagamentos/' . $pagamentoId . '/excluir"]');
        self::assertCount(1, $botao);
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/excluir', [
            '_token' => $botao->attr('data-token'),
            'motivo' => '',
        ]);
        $this->em()->clear();
        self::assertNull($this->em()->find(Pagamento::class, $pagamentoId));

        // 3) Agora o cancelamento PASSA — o portão abriu.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'Erro de lançamento', '_token' => $this->tokenDoFormulario($crawler, 'cancelar_acordo')],
        ]);
        $this->em()->clear();
        self::assertSame(
            StatusAcordo::Cancelado,
            $this->em()->find(Acordo::class, $acordoId)->getStatus(),
            'sem o desfazer, esta linha é inalcançável — era o beco sem saída da spec §3.1',
        );
    }

    #[TestDox('Obrigação coberta por DOIS pagamentos: apagar um NÃO a reabre (o alocado não é zerado)')]
    public function testNaoReabreObrigacaoQueOutroPagamentoAindaCobre(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 5000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        // O MESMO recebimento lançado duas vezes na mesma obrigação de 50,00 (Σ alocado = 100,00).
        $duplicata = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 5000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $legitimo = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 5000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $this->alocar($tenant, $duplicata, $obrigacao, 5000);
        $this->alocar($tenant, $legitimo, $obrigacao, 5000);
        $obrigacao->liquidar(0, 0, 0, 0, new \DateTimeImmutable('2026-05-10'));
        $this->em()->flush();
        $duplicataId = (int) $duplicata->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $botao = $crawler->filter('button[data-acao-url="/cobrancas/pagamentos/' . $duplicataId . '/excluir"]');
        $client->request('POST', '/cobrancas/pagamentos/' . $duplicataId . '/excluir', [
            '_token' => $botao->attr('data-token'),
            'motivo' => 'Duplicidade',
        ]);
        // Sem estes dois, os asserts abaixo passariam mesmo se a rota tivesse recusado o POST — a
        // obrigação continuaria liquidada por não ter acontecido nada.
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');
        $this->em()->clear();
        self::assertNull($this->em()->find(Pagamento::class, $duplicataId), 'a exclusão tem de ter acontecido');

        // Zerar o alocado (o que o handoff mandava) reabriria a dívida e o devedor voltaria a ser cobrado
        // por dinheiro que já pagou — o pior erro possível neste domínio.
        self::assertTrue(
            $this->em()->find(Obrigacao::class, $obrigacaoId)->estaLiquidada(),
            'o pagamento legítimo ainda cobre a obrigação',
        );
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(0, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'o caso segue quitado');
    }

    #[TestDox('🔑 Carteira COM juros: apagar duplicata lançada meses depois NÃO reabre a dívida liquidada antes')]
    public function testNaoReabreLiquidadaAntigaAoApagarDuplicataPosterior(): void
    {
        // O cenário que a carteira neutra dos outros testes é incapaz de produzir — e que a revisão
        // achou. Com juros correndo, o exigível RECALCULADO na data da duplicata é maior do que o
        // exigível que quitou a obrigação lá atrás. Reavaliar a obrigação liquidada contra esse número
        // novo a reabriria, e o devedor voltaria a ser cobrado — com juros — pelo que já pagou.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Juros 1% a.m. simples, sem multa/correção — as carteiras reais têm taxa != 0.
        [, $caso] = $this->semearGrafo($tenant, [], ['taxaJurosMensalBp' => 100]);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-01-01'),
        ])->_real();
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        // Liquidada em 01/02 pelo exigível DAQUELA data: 1000,00 + 1% de 1 mês = 1010,00.
        $legitimo = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'data' => new \DateTimeImmutable('2026-02-01'),
            'valorDivida' => 101000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $this->alocar($tenant, $legitimo, $obrigacao, 101000);
        $obrigacao->liquidar(1000, 0, 0, 0, new \DateTimeImmutable('2026-02-01'));
        $this->em()->flush();
        self::assertSame(101000, $obrigacao->valorExigivel(), 'pré-condição: snapshot congelado em 1010,00');

        // Duplicata lançada em 01/06 — quatro meses depois. Exigível recalculado nessa data seria
        // 1000,00 + 5% = 1050,00, ACIMA do alocado que sobra (1010,00) depois de apagá-la.
        $duplicata = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'data' => new \DateTimeImmutable('2026-06-01'),
            'valorDivida' => 101000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $this->alocar($tenant, $duplicata, $obrigacao, 101000);
        $duplicataId = (int) $duplicata->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $botao = $crawler->filter('button[data-acao-url="/cobrancas/pagamentos/' . $duplicataId . '/excluir"]');
        self::assertCount(1, $botao);
        $client->request('POST', '/cobrancas/pagamentos/' . $duplicataId . '/excluir', [
            '_token' => $botao->attr('data-token'), 'motivo' => 'Duplicidade',
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');
        $this->em()->clear();
        self::assertNull($this->em()->find(Pagamento::class, $duplicataId), 'a exclusão tem de ter acontecido');

        $fresh = $this->em()->find(Obrigacao::class, $obrigacaoId);
        self::assertTrue($fresh->estaLiquidada(), 'o pagamento legítimo de 01/02 quitou a dívida e continua quitando');
        self::assertEquals(new \DateTimeImmutable('2026-02-01'), $fresh->getLiquidadaEm(), 'snapshot original preservado');
        self::assertTrue($fresh->encargosCongelados(), 'o relógio dela continua parado');
        self::assertSame(101000, $fresh->valorExigivel(), 'os juros NÃO voltaram a correr sobre dívida paga');

        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(0, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'o caso segue quitado');
    }

    #[TestDox('🔑 Espelho: apagar o PARCIAL anterior deixa a liquidação a descoberto — a obrigação tem de REABRIR')]
    public function testReabreLiquidadaQueFicouADescobertoAoApagarParcialAnterior(): void
    {
        // O espelho do cenário anterior, e o mais comum dos dois na tela: recebeu um parcial, depois
        // recebeu o que quitou, e apaga o parcial. Aqui a decisão pelo recálculo AO VIVO erra para o
        // outro lado — na data do parcial a dívida era menor, então o que sobra parece cobri-la e a
        // obrigação fica liquidada e CONGELADA com valor a descoberto: o escritório para de cobrar
        // (e de contar juros sobre) dinheiro que não entrou.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [], ['taxaJurosMensalBp' => 100]);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-01-01'),
        ])->_real();
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        // Parcial de R$ 20,00 em 01/02 (exigível daquela data: 1010,33).
        $parcial = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'data' => new \DateTimeImmutable('2026-02-01'),
            'valorDivida' => 2000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $this->alocar($tenant, $parcial, $obrigacao, 2000);

        // Em 01/06 o exigível é 1050,33; entra o que fecha a conta e a obrigação liquida NESSE valor.
        $quitador = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'data' => new \DateTimeImmutable('2026-06-01'),
            'valorDivida' => 103033, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $this->alocar($tenant, $quitador, $obrigacao, 103033);
        $obrigacao->liquidar(5033, 0, 0, 0, new \DateTimeImmutable('2026-06-01'));
        $this->em()->flush();
        self::assertSame(105033, $obrigacao->valorExigivel(), 'pré-condição: snapshot congelado em 1050,33');
        $parcialId = (int) $parcial->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $botao = $crawler->filter('button[data-acao-url="/cobrancas/pagamentos/' . $parcialId . '/excluir"]');
        $client->request('POST', '/cobrancas/pagamentos/' . $parcialId . '/excluir', [
            '_token' => $botao->attr('data-token'), 'motivo' => 'Parcial lançado por engano',
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertNull($this->em()->find(Pagamento::class, $parcialId), 'a exclusão tem de ter acontecido');

        // Sobrou 1030,33 alocado contra uma dívida que valia 1050,33 na quitação: 20,00 a descoberto.
        $fresh = $this->em()->find(Obrigacao::class, $obrigacaoId);
        self::assertFalse($fresh->estaLiquidada(), 'quitada com valor a descoberto é subcobrança silenciosa');
        self::assertNull($fresh->getLiquidadaEm());
        self::assertFalse($fresh->encargosCongelados(), 'o relógio tem de voltar a correr sobre o que falta');

        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertGreaterThan(0, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'o que falta tem de aparecer como saldo');
    }

    #[TestDox('🔑 Terceira porta: apagar recebimento PEQUENO e ANTIGO não pode LIQUIDAR dívida viva')]
    public function testExcluirNuncaLiquidaObrigacaoViva(): void
    {
        // Apagar pagamento só TIRA dinheiro — nunca pode quitar nada. Decidir por data velha invertia
        // isso: o alocado que sobra supera o exigível daquela data antiga (menor, por juros ainda não
        // corridos) e a dívida viva virava quitada e congelada, com o saldo do caso NEGATIVO.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [], ['taxaJurosMensalBp' => 100]);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2025-01-01'),
        ])->_real();
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        // R$ 5,00 em 01/02 (exigível daquela data: 1010,33) e R$ 1.100,00 em 01/12 (exigível: 1111,33).
        // Σ = 1105,00 < 1111,33 → a obrigação segue VIVA, faltam R$ 6,33.
        $antigo = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'data' => new \DateTimeImmutable('2025-02-01'),
            'valorDivida' => 500, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $grande = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'data' => new \DateTimeImmutable('2025-12-01'),
            'valorDivida' => 110000, 'valorEncargos' => 0, 'valorHonorarios' => 0,
        ])->_real();
        $this->alocar($tenant, $antigo, $obrigacao, 500);
        $this->alocar($tenant, $grande, $obrigacao, 110000);
        self::assertFalse($obrigacao->estaLiquidada(), 'pré-condição: a obrigação está VIVA');
        $antigoId = (int) $antigo->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $botao = $crawler->filter('button[data-acao-url="/cobrancas/pagamentos/' . $antigoId . '/excluir"]');
        $client->request('POST', '/cobrancas/pagamentos/' . $antigoId . '/excluir', [
            '_token' => $botao->attr('data-token'), 'motivo' => 'Lançado no caso errado',
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertNull($this->em()->find(Pagamento::class, $antigoId), 'a exclusão tem de ter acontecido');

        $fresh = $this->em()->find(Obrigacao::class, $obrigacaoId);
        self::assertFalse($fresh->estaLiquidada(), 'apagar dinheiro não pode quitar dívida');
        self::assertNull($fresh->getLiquidadaEm());
        self::assertFalse($fresh->encargosCongelados(), 'nem parar o relógio dela');

        // Com a liquidação espúria, o exigível congelava em 1010,33 contra 1100,00 alocados → saldo
        // NEGATIVO, crédito que não existe.
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertGreaterThan(0, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'a dívida que falta continua sendo dívida');
    }

    #[TestDox('Pagamento parcial: o alocado da obrigação zera e o restante volta ao cheio')]
    public function testExcluirPagamentoParcialDevolveOSaldoParcial(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        // Recebe 50,00 numa dívida de 200,00 — parcial, nunca liquida.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10', 'valorPago' => '50,00',
                '_token' => $this->tokenDoFormulario($crawler, 'registrar_pagamento'),
            ],
        ]);
        $this->em()->clear();
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(15000, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'pré-condição: restam 150,00');

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $pagamentoId = (int) $this->pagamentosDoCaso($casoId)[0]->getId();
        $botao = $crawler->filter('button[data-acao-url="/cobrancas/pagamentos/' . $pagamentoId . '/excluir"]');
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/excluir', ['_token' => $botao->attr('data-token')]);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertNull($this->em()->find(Pagamento::class, $pagamentoId), 'a exclusão tem de ter acontecido');

        // O alocado da obrigação zerou e o restante voltou ao cheio.
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(20000, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'o restante volta ao valor cheio');
        self::assertFalse($this->em()->find(Obrigacao::class, $obrigacaoId)->estaLiquidada());
        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(valor), 0) FROM cobranca_alocacao_pagamento WHERE obrigacao_id = ?',
                [$obrigacaoId],
            ),
            'nenhuma alocação sobrou apontando para a obrigação',
        );
    }

    #[TestDox('IDOR: excluir recebimento de OUTRO tenant devolve 404')]
    public function testExcluirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $alheio = PagamentoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();
        $alheioId = (int) $alheio->getId();

        $client->request('POST', '/cobrancas/pagamentos/' . $alheioId . '/excluir', ['_token' => 'irrelevante']);

        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        // SQL nativo de propósito: em WebTestCase o TenantFilter fica LIGADO e `find()` não enxergaria a
        // linha de outro tenant — o assert passaria por não ver, não por ela existir.
        self::assertSame(1, $this->linhasDePagamento($alheioId), 'o pagamento alheio continua intacto');
    }

    #[TestDox('Sem a capacidade financeira: excluir é negado e o recebimento continua lá')]
    public function testExcluirSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $pagamentoId = (int) $pagamento->getId();

        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/excluir', ['_token' => 'irrelevante']);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Pagamento::class, $pagamentoId), 'sem capacidade não apaga');
    }

    #[TestDox('Quem tem `gerenciar` mas NÃO tem `movimentacao_financeira` também não apaga')]
    public function testExcluirExigeACapacidadeFinanceiraEspecificamente(): void
    {
        // Discrimina QUAL capacidade manda. O teste do operador sem capacidade nenhuma não faz isso:
        // ele passa mesmo que a rota exija a capacidade errada — medido, e foi o que aconteceu.
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant);
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $pagamentoId = (int) $pagamento->getId();

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/excluir', [
            '_token' => $this->tokenCsrf($client, 'excluir_pagamento_' . $pagamentoId),
        ]);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame(1, $this->linhasDePagamento($pagamentoId), 'gerenciar não dá direito de mexer em dinheiro');
    }

    #[TestDox('CSRF inválido: o recebimento não é apagado')]
    public function testExcluirCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $pagamentoId = (int) $pagamento->getId();

        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/excluir', ['_token' => 'falso']);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Pagamento::class, $pagamentoId), 'token inválido não apaga');
    }

    #[TestDox('Caso encerrado: excluir é recusado (mesma regra da correção)')]
    public function testExcluirEmCasoEncerrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $pagamentoId = (int) $pagamento->getId();

        // GET antes: `tokenCsrf` precisa de uma request na pilha, e o token tem de nascer na MESMA sessão
        // do POST — senão a recusa viria do CSRF e a regra de caso encerrado nunca seria exercida.
        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/excluir', [
            '_token' => $this->tokenCsrf($client, 'excluir_pagamento_' . $pagamentoId),
        ]);

        self::assertResponseRedirects();
        // A flash discrimina QUAL guarda atuou: sem ela, um CSRF recusado faria o teste passar sem que a
        // regra de caso encerrado chegasse a ser exercida.
        self::assertStringContainsString(
            'encerrado',
            implode(' ', $client->getRequest()->getSession()->getFlashBag()->peekAll()['danger'] ?? []),
            'a recusa tem de vir da regra de caso encerrado, não do CSRF',
        );
        $this->em()->clear();
        self::assertSame(1, $this->linhasDePagamento($pagamentoId), 'caso encerrado não aceita movimentação financeira');
    }

    // ---------------------------------------------------------------- helpers

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** A linha existe no banco? SQL nativo — passa por baixo do TenantFilter e do identity map. */
    private function linhasDePagamento(int $pagamentoId): int
    {
        return (int) $this->em()->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM cobranca_pagamento WHERE id = ?', [$pagamentoId]);
    }

    /** @return Pagamento[] */
    private function pagamentosDoCaso(int $casoId): array
    {
        return static::getContainer()->get(\App\Cobranca\Repository\PagamentoRepository::class)
            ->doCaso($this->em()->find(CasoCobranca::class, $casoId));
    }

    private function alocar(object $tenant, Pagamento $pagamento, Obrigacao $obrigacao, int $valor): void
    {
        $alocacao = (new AlocacaoPagamento())
            ->setTenant($tenant)
            ->setObrigacao($obrigacao)
            ->setValor($valor);
        $pagamento->adicionarAlocacao($alocacao);
        $this->em()->persist($alocacao);
        $this->em()->flush();
    }
}
