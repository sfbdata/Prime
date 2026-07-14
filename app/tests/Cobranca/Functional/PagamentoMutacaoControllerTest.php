<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\PagamentoController;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\PagamentoRepository;
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
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '100,00']],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($caso);
        self::assertCount(1, $pagamentos);
        self::assertSame(10000, $pagamentos[0]->getValorDivida());
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

        // Σ alocações (50,00) ≠ parte-dívida do valor pago (100,00) → PagamentoInconsistenteException.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '100,00',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '50,00']],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

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
                'data' => '2026-05-10', 'valorPago' => '100,00',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '100,00']],
                '_token' => 'token-falso',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

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
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '80,00']],
                'motivoCorrecao' => 'Valor lançado a maior',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

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
                'valorPago' => '80,00',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '80,00']],
                'motivoCorrecao' => 'MARCADOR CSRF', '_token' => 'token-falso',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Pagamento::class, $pagamentoId);
        self::assertSame(20000, $fresh->getValorDivida(), 'CSRF inválido não reescreve a composição');
        self::assertNull($fresh->getMotivoCorrecao());
    }
}
