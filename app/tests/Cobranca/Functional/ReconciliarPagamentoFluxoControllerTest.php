<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Controller\ObrigacaoController;
use App\Cobranca\Controller\PagamentoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * F5 (spec §5/§8/§12) — o fluxo PAGO → EDITADO → RECONCILIADO ponta a ponta, contra o banco real.
 *
 * A infra de F5 já existia (editar obrigação paga já era permitido; corrigir pagamento já reescrevia o
 * recebido sem estorno); o que faltava era a PROVA de que editar uma obrigação paga não corrompe o saldo
 * e que a reconciliação fecha, com o histórico completo. Estes testes fecham essa lacuna e cobrem a
 * descoberta da reconciliação na UI (o botão "Editar" da linha sinaliza pagamento, o modal tem o aviso).
 *
 * INVARIANTE provada: `CalculadoraSaldo::saldoExigivel` segue derivando corretamente
 * (Σ valorExigivel − Σ alocado − liquidado) — o saldo sobe pela diferença editada, sem número negativo
 * espúrio nem alocação órfã; nenhum centavo se move no pagamento por conta da edição da obrigação.
 */
#[CoversClass(ObrigacaoController::class)]
#[CoversClass(PagamentoController::class)]
#[CoversClass(ObjetoController::class)]
final class ReconciliarPagamentoFluxoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Editar obrigação PAGA aumentando o valor: ela volta a ter saldo, o pagamento fica intacto')]
    public function testEditarObrigacaoPagaReabreSaldoSemMoverPagamento(): void
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

        // 1) Registra um pagamento (AUTO/FIFO) que QUITA a obrigação. Carteira SemPercentual (default do
        //    grafo) → honorários 0 → parte-dívida = valor pago → aloca 20000 na única obrigação.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenPag = $this->tokenDoFormulario($crawler, 'registrar_pagamento');
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => ['data' => '2026-05-10', 'valorPago' => '200,00', '_token' => $tokenPag],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');

        // Pré-condição: o caso está quitado (saldo exigível zero).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        $em->clear();
        self::assertSame(0, $calc->saldoExigivel($em->find(CasoCobranca::class, $casoId)), 'o pagamento quitou o caso');

        // 2) Edita a obrigação PAGA aumentando o valor (200,00 → 300,00). Exigível novo 30000 fica acima
        //    do alocado (20000) → passa no guard, congela os encargos e volta a abrir saldo.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenEdit = $this->tokenDoFormulario($crawler, 'editar_obrigacao');
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Cota reajustada',
                'valorOriginal' => '300,00',
                'vencimentoOriginal' => '2026-05-01',
                'motivo' => 'reajuste retroativo do valor devido',
                '_token' => $tokenEdit,
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        $em->clear();
        $casoFresh = $em->find(CasoCobranca::class, $casoId);

        // A obrigação voltou a ter saldo: exigível subiu para 30000, deixou de estar quitada.
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame(30000, $fresh->valorExigivel(), 'o exigível subiu pela diferença editada');

        // INVARIANTE: o saldo do caso sobe EXATAMENTE pela diferença (30000 − 20000 = 10000). Sem número
        // negativo espúrio, sem alocação órfã — a derivação continua correta.
        self::assertSame(10000, $calc->saldoExigivel($casoFresh), 'o saldo do caso subiu só pela diferença editada');

        // O pagamento e a alocação continuam íntegros — a edição da obrigação NÃO os tocou.
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($casoFresh);
        self::assertCount(1, $pagamentos);
        self::assertSame(20000, $pagamentos[0]->getValorDivida(), 'o valor recebido não mudou');
        self::assertCount(1, $pagamentos[0]->getAlocacoes());
        self::assertSame(20000, $pagamentos[0]->getAlocacoes()->first()->getValor(), 'nenhum centavo se moveu na alocação');
    }

    #[TestDox('Corrigir o pagamento reconcilia: a obrigação requita e o histórico tem ObrigacaoEditada antes de PagamentoCorrigido')]
    public function testReconciliarPeloCorrigirPagamentoRequitaEHistoricoTemOsDoisEventos(): void
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

        // 1) Paga a obrigação (AUTO/FIFO, 200,00 → quita).
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenPag = $this->tokenDoFormulario($crawler, 'registrar_pagamento');
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => ['data' => '2026-05-10', 'valorPago' => '200,00', '_token' => $tokenPag],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');

        $pagamentoId = (int) static::getContainer()->get(PagamentoRepository::class)
            ->doCaso(static::getContainer()->get(EntityManagerInterface::class)->find(CasoCobranca::class, $casoId))[0]->getId();

        // 2) Edita a obrigação aumentando o valor → reabre saldo (restante 10000).
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenEdit = $this->tokenDoFormulario($crawler, 'editar_obrigacao');
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Cota reajustada',
                'valorOriginal' => '300,00',
                'vencimentoOriginal' => '2026-05-01',
                'motivo' => 'reajuste retroativo',
                '_token' => $tokenEdit,
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-divida');

        // 3) Corrige o pagamento (AUTO/FIFO) para 300,00 → reconcilia o recebido com o novo exigível.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenCorr = $this->tokenDoFormulario($crawler, 'corrigir_pagamento');
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/corrigir', [
            'corrigir_pagamento' => [
                'valorPago' => '300,00',
                'motivoCorrecao' => 'reconciliar o recebido apos o reajuste',
                '_token' => $tokenCorr,
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        $em->clear();
        $casoFresh = $em->find(CasoCobranca::class, $casoId);

        // Reconciliado: a obrigação voltou a ficar quitada e o caso zerou de novo.
        self::assertSame(0, $calc->saldoExigivel($casoFresh), 'a correção do pagamento requitou o caso');
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($casoFresh);
        self::assertSame(30000, $pagamentos[0]->getValorDivida(), 'o pagamento passou a cobrir os 30000');

        // O histórico tem os DOIS eventos, e na ordem que a TELA usa: `doCaso` devolve do mais
        // RECENTE para o mais antigo (2026-07-23, a pedido do dono — a anotação nova aparece em
        // cima). Então a correção do pagamento, que aconteceu depois, vem antes na lista. A
        // sequência real do fluxo (edição → correção) continua a mesma; o que mudou é a leitura.
        // Índices, não posições fixas, para não depender de outros eventos que o caso possa ter.
        $eventos = static::getContainer()->get(EventoHistoricoRepository::class)->doCaso($casoFresh);
        $tipos = array_map(static fn ($e) => $e->getTipo(), $eventos);
        $idxEdicao = array_search(TipoEventoHistorico::ObrigacaoEditada, $tipos, true);
        $idxCorrecao = array_search(TipoEventoHistorico::PagamentoCorrigido, $tipos, true);
        self::assertNotFalse($idxEdicao, 'o histórico registra a edição da obrigação');
        self::assertNotFalse($idxCorrecao, 'o histórico registra a correção do pagamento');
        self::assertLessThan($idxEdicao, $idxCorrecao, 'o mais recente (correção) aparece ANTES na lista');
    }

    #[TestDox('IDOR: editar obrigação PAGA de OUTRO tenant devolve 404 (o pagamento não afrouxa a guarda)')]
    public function testEditarObrigacaoPagaDeOutroTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        // Grafo alheio com uma obrigação JÁ PAGA (alocação real) — o caso "paga" do IDOR do editar.
        $tenantAlheio = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($tenantAlheio);
        $obrigacaoAlheia = ObrigacaoFactory::createOne([
            'tenant' => $tenantAlheio, 'caso' => $casoAlheio, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamentoAlheio = PagamentoFactory::createOne([
            'tenant' => $tenantAlheio, 'caso' => $casoAlheio, 'valorDivida' => 20000, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenantAlheio, 'pagamento' => $pagamentoAlheio, 'obrigacao' => $obrigacaoAlheia, 'valor' => 20000,
        ]);

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoAlheia->getId() . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Invasao', 'valorOriginal' => '150,00',
                'vencimentoOriginal' => '2026-09-01', 'motivo' => 'x', '_token' => 'irrelevante',
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('F5.2: o botão Editar da linha sinaliza pagamento e o modal traz o aviso de reconciliação')]
    public function testBotaoEditarSinalizaPagamentoEModalTemAvisoDeReconciliacao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Obrigação A: PAGA (tem alocação → `alocado > 0`).
        $paga = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0, 'descricao' => 'Com pagamento',
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $paga, 'valor' => 20000,
        ]);
        // Obrigação B: SEM pagamento (`alocado == 0`).
        $semPag = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0, 'descricao' => 'Sem pagamento',
        ])->_real();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        // O gatilho do aviso: `data-tem-pagamento` reflete `o.alocado > 0` no botão "Editar" da linha.
        self::assertSame(
            '1',
            $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $paga->getId() . '/editar"]')->attr('data-tem-pagamento'),
            'a obrigação com pagamento sinaliza tem-pagamento=1',
        );
        self::assertSame(
            '0',
            $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $semPag->getId() . '/editar"]')->attr('data-tem-pagamento'),
            'a obrigação sem pagamento sinaliza tem-pagamento=0',
        );

        // O modal reutilizável tem o bloco de aviso, e ele nasce escondido (o JS o revela pelo gatilho).
        self::assertSelectorExists('#modalEditarObrigacao [data-aviso-reconciliar]', 'o modal de editar tem o aviso de reconciliação');
        self::assertSelectorExists('#modalEditarObrigacao [data-aviso-reconciliar].d-none', 'o aviso nasce escondido');
    }
}
