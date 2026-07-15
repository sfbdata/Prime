<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\AcordoController;
use App\Cobranca\Enum\StatusAcordo;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Detalhe do Acordo (Ajuste 7, Fatia 3): tela de LEITURA `cobranca_acordo_show`. Gate só de MÓDULO
 * (leitura), anti-IDOR (404 cross-tenant), e a derivação visível (total do snapshot, desconto,
 * parcelas com o pago). Não faz mutação → sem CSRF.
 */
#[CoversClass(AcordoController::class)]
final class AcordoDetalheControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Detalhe do acordo: renderiza parcelas, substituídas e o desconto derivado')]
    public function testShowRenderizaDetalhe(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Total negociado 1.000,00 (entrada 400,00) substituindo 1.200,00 → desconto 200,00.
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'status' => StatusAcordo::Ativo,
            'valorTotalNegociado' => 100000,
            'valorEntrada' => 40000,
        ])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Entrada', 'valorOriginal' => 40000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Divida antiga', 'valorOriginal' => 100000, 'encargosReconhecidos' => 20000, 'acordoSubstituto' => $acordo]);

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        self::assertResponseIsSuccessful();
        $texto = $crawler->filter('.cobrancas-page')->text();
        // Snapshot da negociação + derivação do desconto (1.200,00 − 1.000,00).
        self::assertStringContainsString('1.000,00', $texto, 'total negociado');
        self::assertStringContainsString('400,00', $texto, 'entrada');
        self::assertStringContainsString('Desconto concedido', $texto);
        self::assertStringContainsString('200,00', $texto, 'desconto derivado');
        // Parcelas e substituídas listadas.
        self::assertStringContainsString('Parcela 1/2', $texto);
        self::assertStringContainsString('Divida antiga', $texto);
        self::assertStringContainsString('Em aberto', $texto);
    }

    #[TestDox('Detalhe do acordo antigo (sem snapshot): total derivado da soma das parcelas')]
    public function testShowDerivaTotalSemSnapshot(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'status' => StatusAcordo::Ativo,
            'valorTotalNegociado' => null,
            'valorEntrada' => 0,
        ])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1', 'valorOriginal' => 25000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo]);

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('250,00', $crawler->filter('.cobrancas-page')->text(), 'total derivado das parcelas');
    }

    #[TestDox('Parcelas pagas: alocação REAL vira "Quitada"/"Parcial" com o valor pago na tela')]
    public function testShowMostraOPagoDeCadaParcela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo,
            'valorTotalNegociado' => 60000, 'valorEntrada' => 0,
        ])->_real();
        $quitada = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo])->_real();
        $parcial = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo])->_real();

        // Pagamento REAL alocado: a 1ª parcela quitada (300,00) e a 2ª parcial (100,00).
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 40000, 'valorHonorarios' => 0])->_real();
        AlocacaoPagamentoFactory::createOne(['tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $quitada, 'valor' => 30000]);
        AlocacaoPagamentoFactory::createOne(['tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $parcial, 'valor' => 10000]);

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        self::assertResponseIsSuccessful();
        $linhas = $crawler->filter('table')->eq(0)->filter('tbody tr');
        // A 1ª parcela: pago == valor → Quitada. Prova o lookup real do alocado por obrigação.
        self::assertStringContainsString('Quitada', $linhas->eq(0)->text());
        self::assertStringContainsString('300,00', $linhas->eq(0)->text());
        // A 2ª: pago < valor → Parcial, com o valor pago visível.
        self::assertStringContainsString('Parcial', $linhas->eq(1)->text());
        self::assertStringContainsString('100,00', $linhas->eq(1)->text());
        // Total pago do acordo (derivado das alocações).
        self::assertStringContainsString('Pago até agora: R$ 400,00', $crawler->filter('.cobrancas-page')->text());
    }

    #[TestDox('Parcelas saem ordenadas por vencimento, não pela ordem física da tabela')]
    public function testShowOrdenaParcelasPorVencimento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        // Criadas FORA de ordem de propósito: a 3/3 nasce primeiro.
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 3/3', 'valorOriginal' => 10000, 'encargosReconhecidos' => 0, 'vencimentoOriginal' => new \DateTimeImmutable('2026-11-01'), 'acordoOrigem' => $acordo]);
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/3', 'valorOriginal' => 10000, 'encargosReconhecidos' => 0, 'vencimentoOriginal' => new \DateTimeImmutable('2026-09-01'), 'acordoOrigem' => $acordo]);
        $meio = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/3', 'valorOriginal' => 10000, 'encargosReconhecidos' => 0, 'vencimentoOriginal' => new \DateTimeImmutable('2026-10-01'), 'acordoOrigem' => $acordo])->_real();

        // UPDATE reescreve a tupla no fim da heap: sem ORDER BY explícito, a 2/3 iria para o fim.
        $meio->reconhecerEncargos(500);
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        self::assertResponseIsSuccessful();
        $linhas = $crawler->filter('table')->eq(0)->filter('tbody tr');
        self::assertStringContainsString('Parcela 1/3', $linhas->eq(0)->text());
        self::assertStringContainsString('Parcela 2/3', $linhas->eq(1)->text());
        self::assertStringContainsString('Parcela 3/3', $linhas->eq(2)->text());
    }

    #[TestDox('Acordo rompido: mostra o aviso de desfeito e o motivo')]
    public function testShowAcordoRompidoMostraAvisoEMotivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'status' => StatusAcordo::Rompido, 'motivoRompimento' => 'Parou de pagar',
        ])->_real();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        self::assertResponseIsSuccessful();
        $texto = $crawler->filter('.cobrancas-page')->text();
        self::assertStringContainsString('Acordo desfeito', $texto);
        self::assertStringContainsString('Parou de pagar', $texto);
    }

    #[TestDox('Sem o módulo de cobranças: acesso negado (não renderiza o acordo)')]
    public function testShowSemModuloNegado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarUsuarioSemModulo($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();

        $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        // Alvo explícito: `semAcesso()` manda para a home. Sem o alvo, um 302 para /login
        // (usuário não autenticado) passaria igual e o teste não provaria o gate de módulo.
        self::assertResponseRedirects('/');
    }

    #[TestDox('IDOR: detalhe de acordo de outro tenant → 404')]
    public function testShowCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();

        $client->request('GET', '/cobrancas/acordos/' . $acordoAlheio->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Detalhe é leitura: operador SEM a capacidade de gerenciar consegue abrir')]
    public function testShowExigeApenasOModulo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();

        $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        self::assertResponseIsSuccessful();
    }
}
