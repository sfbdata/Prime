<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Repository\PagamentoRepository;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Prova que as capacidades de Cobrança são INDEPENDENTES (SPEC §22): `resources.cobranca.gerenciar`
 * (operar) e `resources.cobranca.movimentacao_financeira` (caixa) não se implicam. Um operador com
 * uma NÃO ganha a outra — nem no servidor. Fecha a lacuna de cobertura em que todos os happy paths
 * usam admin `isSystem` (bypass de todas as capacidades).
 */
final class CapacidadeSeparacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Só movimentação financeira: registra pagamento, mas NÃO registra obrigação (gerenciar nega)')]
    public function testFinanceiroSemGerenciar(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.movimentacao_financeira']);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 10000, 'encargosReconhecidos' => 0,
        ])->_real();
        $casoId = (int) $caso->getId();

        // Financeiro FUNCIONA: o modal financeiro renderiza (podeMovimentar) e o pagamento persiste.
        $crawler = $client->request('GET', '/cobrancas/casos/' . $casoId);
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10', 'valorPago' => '100,00',
                'alocacoes' => [['obrigacaoId' => (string) $obrigacao->getId(), 'valor' => '100,00']],
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, static::getContainer()->get(PagamentoRepository::class)->doCaso($caso), 'financeiro deve funcionar');

        // Gerenciar é NEGADO: registrar obrigação redireciona para fora do caso (gate antes do CSRF).
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-08-10', '_token' => 'irrelevante'],
        ]);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'), 'gerenciar deve ser negado a um operador só-financeiro');
    }

    #[TestDox('Só gerenciar: registra obrigação, mas NÃO registra pagamento (financeiro nega)')]
    public function testGerenciarSemFinanceiro(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        // Gerenciar FUNCIONA: registra obrigação.
        $crawler = $client->request('GET', '/cobrancas/casos/' . $casoId);
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'OBRIG MARCADOR ZZ', 'valorOriginal' => '15,00', 'vencimentoOriginal' => '2026-08-10', '_token' => $token],
        ]);
        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);
        $client->followRedirect();
        self::assertStringContainsString('OBRIG MARCADOR ZZ', (string) $client->getResponse()->getContent(), 'gerenciar deve funcionar');

        // Financeiro é NEGADO: registrar pagamento redireciona para fora do caso.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => ['data' => '2026-05-10', 'valorPago' => '100,00', 'alocacoes' => [], '_token' => 'irrelevante'],
        ]);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'), 'financeiro deve ser negado a um operador só-gerenciar');
    }
}
