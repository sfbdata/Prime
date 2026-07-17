<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\PagamentoController;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\PagamentoRepository;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações financeiras de Pagamento (Onda 8B-D): registrar e corrigir. Cobre gate de módulo +
 * capacidade `resources.cobranca.movimentacao_financeira` (SEPARADA de gerenciar), CSRF, anti-IDOR
 * cross-tenant (404), erro de domínio (alocação inconsistente / caso encerrado) e o happy path com
 * persistência. Carteira SemPercentual (default) → honorários 0 → parte-dívida = valor pago.
 */
#[CoversClass(PagamentoController::class)]
final class PagamentoMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Registrar pagamento: happy path persiste com a alocação e volta ao caso')]
    public function testRegistrarPagamentoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '100,00',
                'alocarManualmente' => '1',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '100,00']],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(1, $pagamentos);
        self::assertSame(10000, $pagamentos[0]->getValorDivida());
    }

    #[TestDox('Registrar AUTO (FIFO) em acrescido_divida: só o valor pago basta, sem alocar à mão')]
    public function testRegistrarAutoFifoAcrescidoDivida(): void
    {
        // Prova o fim do "alvo invisível": o gestor digita SÓ o bruto (110,00); o sistema separa
        // honorários (10,00) e aloca a dívida (100,00) por FIFO — sem nenhuma linha de alocação manual.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '10.00',
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        // Sem 'alocarManualmente' e sem 'alocacoes': auto-alocação FIFO.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '110,00',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(1, $pagamentos);
        self::assertSame(10000, $pagamentos[0]->getValorDivida(), 'FIFO alocou a parte-dívida (100,00)');
        self::assertSame(1000, $pagamentos[0]->getValorHonorarios(), 'honorários derivados (10,00)');
        self::assertCount(1, $pagamentos[0]->getAlocacoes());
        self::assertSame(10000, $pagamentos[0]->getAlocacoes()->first()->getValor());
    }

    #[TestDox('Registrar AUTO acima do saldo: bloqueia (não persiste), volta ao objeto')]
    public function testRegistrarAutoBloqueiaSobrepagamento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        // Auto: paga 150,00 num caso cujo saldo é 100,00 → PagamentoExcedeSaldoException.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '150,00',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(0, $pagamentos, 'sobrepagamento não pode persistir');
    }

    #[TestDox('Prévia (GET): retorna a divisão dívida/honorários + quebra FIFO em acrescido_divida')]
    public function testPreviaRegistrarRetornaDivisao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '10.00',
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ]);
        $casoId = (int) $caso->getId();

        // Bruto 11000 centavos → dívida 10000 + honorários 1000.
        $client->request('GET', '/cobrancas/casos/' . $casoId . '/pagamento-previa?valor=11000');

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(10000, $json['divida']);
        self::assertSame(1000, $json['honorarios']);
        self::assertFalse($json['excede']);
        self::assertCount(1, $json['alocacoes']);
        self::assertSame(10000, $json['alocacoes'][0]['valor']);
    }

    #[TestDox('Prévia (GET): sinaliza excede quando o valor passa do saldo')]
    public function testPreviaSinalizaExcede(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ]);
        $casoId = (int) $caso->getId();

        $client->request('GET', '/cobrancas/casos/' . $casoId . '/pagamento-previa?valor=15000');

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($json['excede']);
        self::assertSame(5000, $json['excedeEm']);
        self::assertSame([], $json['alocacoes']);
    }

    #[TestDox('Prévia (GET) sem a capacidade financeira: 403')]
    public function testPreviaSemCapacidade403(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->request('GET', '/cobrancas/casos/' . $caso->getId() . '/pagamento-previa?valor=10000');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Prévia (GET) de caso de OUTRO tenant: 404')]
    public function testPreviaCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('GET', '/cobrancas/casos/' . $casoAlheio->getId() . '/pagamento-previa?valor=10000');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Prévia da correção (GET) de pagamento de OUTRO tenant: 404')]
    public function testPreviaCorrigirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $pagamentoAlheio = PagamentoFactory::createOne([
            'tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio,
        ])->_real();

        $client->request('GET', '/cobrancas/pagamentos/' . $pagamentoAlheio->getId() . '/pagamento-previa?valor=10000');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Prévia da correção (GET): exclui o próprio pagamento da sala')]
    public function testPreviaCorrigirExcluiProprioPagamento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 20000,
        ]);

        // A obrigação está 100% coberta pelo próprio pagamento; a prévia da correção o devolve à sala.
        $client->request('GET', '/cobrancas/pagamentos/' . $pagamento->getId() . '/pagamento-previa?valor=15000');

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($json['excede'], 'a correção não pode contar as próprias alocações contra a sala');
        self::assertSame(20000, $json['saldoDisponivel']);
        self::assertSame(15000, $json['divida']);
    }

    #[TestDox('Registrar AUTO ignora alocações submetidas (o FIFO vence; sem alocarManualmente)')]
    public function testRegistrarAutoIgnoraAlocacoesSubmetidas(): void
    {
        // Segurança: mesmo submetendo alocações à mão, sem o toggle o FIFO assume; o montar revalida.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        // alocarManualmente ausente (auto), mas manda uma alocação BOBA de 30,00 — deve ser IGNORADA.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '100,00',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '30,00']],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(1, $pagamentos);
        // FIFO alocou os 100,00 (não os 30,00 submetidos).
        self::assertSame(10000, $pagamentos[0]->getValorDivida());
        self::assertCount(1, $pagamentos[0]->getAlocacoes());
        self::assertSame(10000, $pagamentos[0]->getAlocacoes()->first()->getValor());
    }

    #[TestDox('Registrar MANUAL sem nenhuma alocação: validação barra, não persiste')]
    public function testRegistrarManualSemLinhasFalha(): void
    {
        // O #[Assert\Callback] condicional exige ≥1 alocação no modo manual → form inválido, PRG sem gravar.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '100,00',
                'alocarManualmente' => '1', // manual, mas sem nenhuma linha de alocação.
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(0, $pagamentos, 'modo manual sem alocação não pode persistir');
    }

    #[TestDox('Corrigir AUTO (banco real): exclui as alocações do próprio pagamento — prova a ordem crítica')]
    public function testCorrigirAutoFifoExcluiProprioPagamentoBancoReal(): void
    {
        // Obrigação 200,00 já 100% coberta pelas alocações DESTE pagamento. Corrigir em AUTO precisa
        // devolver essas alocações à sala (ordem: alocar ANTES de limpar); senão a sala seria 0 e
        // bloquearia. Com banco real, prova a interação query-vs-flush que o unit com mock não cobre.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 0,
        ])->_real();
        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 20000,
        ]);
        $pagamentoId = (int) $pagamento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'corrigir_pagamento');

        // Correção AUTO (sem alocarManualmente, sem alocacoes): para 150,00.
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/corrigir', [
            'corrigir_pagamento' => [
                'valorPago' => '150,00',
                'motivoCorrecao' => 'Reduzir para 150 (auto)',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Pagamento::class, $pagamentoId);
        self::assertSame(15000, $fresh->getValorDivida(), 'FIFO recompôs para 150,00 (ordem crítica OK)');
        self::assertSame('Reduzir para 150 (auto)', $fresh->getMotivoCorrecao());
        self::assertCount(1, $fresh->getAlocacoes());
        self::assertSame(15000, $fresh->getAlocacoes()->first()->getValor());
    }

    #[TestDox('Registrar pagamento com alocação inconsistente: erro de domínio, não persiste')]
    public function testRegistrarPagamentoInconsistente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        // Modo MANUAL: Σ alocações (50,00) ≠ parte-dívida do valor pago (100,00) → PagamentoInconsistenteException.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '100,00',
                'alocarManualmente' => '1',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '50,00']],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(0, $pagamentos, 'alocação inconsistente não pode persistir pagamento');
    }

    #[TestDox('Registrar pagamento sem a capacidade financeira: negado (redirect, não caso)')]
    public function testRegistrarPagamentoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => ['data' => '2026-05-10', 'valorPago' => '100,00', 'alocacoes' => [], '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'), 'a capacidade nega antes do CSRF; vai para a homepage, não para o caso');
    }

    #[TestDox('IDOR: registrar pagamento em caso de OUTRO tenant devolve 404')]
    public function testRegistrarPagamentoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/pagamentos', [
            'registrar_pagamento' => ['data' => '2026-05-10', 'valorPago' => '100,00', 'alocacoes' => [], '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: registrar pagamento não persiste')]
    public function testRegistrarPagamentoCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10', 'valorPago' => '100,00', 'alocarManualmente' => '1',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '100,00']],
                '_token' => 'token-falso',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(0, $pagamentos, 'CSRF inválido não pode persistir pagamento');
    }

    #[TestDox('Corrigir pagamento: happy path reescreve a composição e mantém o motivo')]
    public function testCorrigirPagamentoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 0,
        ])->_real();
        $pagamentoId = (int) $pagamento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'corrigir_pagamento');

        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/corrigir', [
            'corrigir_pagamento' => [
                'valorPago' => '80,00',
                'alocarManualmente' => '1',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '80,00']],
                'motivoCorrecao' => 'Valor lançado a maior',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Pagamento::class, $pagamentoId);
        self::assertSame(8000, $fresh->getValorDivida(), 'composição reescrita para R$80,00');
        self::assertSame('Valor lançado a maior', $fresh->getMotivoCorrecao());
    }

    #[TestDox('IDOR: corrigir pagamento de OUTRO tenant devolve 404')]
    public function testCorrigirPagamentoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $pagamentoAlheio = PagamentoFactory::createOne([
            'tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio,
        ])->_real();

        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoAlheio->getId() . '/corrigir', [
            'corrigir_pagamento' => ['valorPago' => '10,00', 'alocacoes' => [], 'motivoCorrecao' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Corrigir pagamento sem a capacidade financeira: negado, não altera')]
    public function testCorrigirPagamentoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 0,
        ])->_real();
        $pagamentoId = (int) $pagamento->getId();

        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/corrigir', [
            'corrigir_pagamento' => ['valorPago' => '80,00', 'alocacoes' => [], 'motivoCorrecao' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Pagamento::class, $pagamentoId)->getMotivoCorrecao(), 'sem capacidade não corrige');
    }

    #[TestDox('CSRF inválido: corrigir pagamento não altera a composição')]
    public function testCorrigirPagamentoCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
        ])->_real();
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 0,
        ])->_real();
        $pagamentoId = (int) $pagamento->getId();

        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/corrigir', [
            'corrigir_pagamento' => [
                'valorPago' => '80,00', 'alocarManualmente' => '1',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '80,00']],
                'motivoCorrecao' => 'MARCADOR CSRF', '_token' => 'token-falso',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-movimentos');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Pagamento::class, $pagamentoId);
        self::assertSame(20000, $fresh->getValorDivida(), 'CSRF inválido não reescreve a composição');
        self::assertNull($fresh->getMotivoCorrecao());
    }

    #[TestDox('B5: pagamento com valor não-positivo reabre o modal com o digitado e o erro')]
    public function testRegistrarPagamentoInvalidoReabreModalComErroEPreservaODigitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        // Valor 0,00 passa no HTML5 (campo preenchido) mas falha Positive no servidor.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => ['data' => '2026-05-10', 'valorPago' => '0,00', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');
        $crawler = $client->followRedirect();

        self::assertSame('modalRegistrarPagamento', $crawler->filter('[data-modal-erro]')->attr('data-modal-erro'));
        $modalHtml = $crawler->filter('#modalRegistrarPagamento')->html();
        self::assertStringContainsString('O valor pago deve ser positivo.', $modalHtml);
        self::assertStringContainsString('2026-05-10', $modalHtml, 'a data digitada tem de sobreviver ao redirect');
    }

    #[TestDox('B5: corrigir pagamento invalido reabre o modal reutilizavel com a URL da acao e o digitado')]
    public function testCorrigirPagamentoInvalidoReabreModalComAcaoEPreservaODigitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0])->_real();
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 20000, 'valorHonorarios' => 0])->_real();
        $pagamentoId = (int) $pagamento->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'corrigir_pagamento');

        // Valor 0,00 falha Positive no servidor; o motivo fica preenchido para provar a preservação.
        $client->request('POST', '/cobrancas/pagamentos/' . $pagamentoId . '/corrigir', [
            'corrigir_pagamento' => ['valorPago' => '0,00', 'motivoCorrecao' => 'Ajuste de smoke ZZZ', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-movimentos');
        $crawler = $client->followRedirect();

        $marcador = $crawler->filter('[data-modal-erro]');
        self::assertSame('modalCorrigirPagamento', $marcador->attr('data-modal-erro'));
        self::assertSame('/cobrancas/pagamentos/' . $pagamentoId . '/corrigir', $marcador->attr('data-modal-erro-acao'));
        $modalHtml = $crawler->filter('#modalCorrigirPagamento')->html();
        self::assertStringContainsString('O valor pago deve ser positivo.', $modalHtml);
        self::assertStringContainsString('Ajuste de smoke ZZZ', $modalHtml, 'o motivo digitado tem de sobreviver ao redirect');
    }
}
