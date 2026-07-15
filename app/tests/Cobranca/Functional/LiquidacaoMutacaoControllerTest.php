<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\LiquidacaoController;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\LiquidacaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutação financeira de Liquidação não monetária (Onda 8B-D). Cobre gate de módulo + capacidade
 * `resources.cobranca.movimentacao_financeira`, CSRF, anti-IDOR cross-tenant (404), erro de domínio
 * (caso encerrado) e o happy path com persistência. O que reduz o saldo é o valor reconhecido.
 */
#[CoversClass(LiquidacaoController::class)]
final class LiquidacaoMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Registrar liquidação: happy path persiste com o valor reconhecido')]
    public function testRegistrarLiquidacaoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_liquidacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/liquidacoes', [
            'registrar_liquidacao' => [
                'tipo' => 'bem_movel',
                'descricaoBem' => 'Veículo dado em pagamento',
                'valorAtribuidoBem' => '150,00',
                'valorReconhecido' => '120,00',
                'data' => '2026-05-10',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $liquidacoes = static::getContainer()->get(LiquidacaoRepository::class)->doCaso($caso);
        self::assertCount(1, $liquidacoes);
        self::assertSame(12000, $liquidacoes[0]->getValorReconhecido(), 'o que reduz o saldo é o reconhecido');
        self::assertSame(15000, $liquidacoes[0]->getValorAtribuidoBem());
    }

    #[TestDox('Registrar liquidação em caso ENCERRADO: erro de domínio, não persiste')]
    public function testRegistrarLiquidacaoCasoEncerrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $casoAtivo] = $this->semearGrafo($tenant);
        [, $casoEncerrado] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);

        // Token do caso ativo (o encerrado não renderiza o modal financeiro).
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $casoAtivo->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_liquidacao');

        $client->request('POST', '/cobrancas/casos/' . $casoEncerrado->getId() . '/liquidacoes', [
            'registrar_liquidacao' => [
                'tipo' => 'bem_movel', 'descricaoBem' => 'Bem', 'valorReconhecido' => '100,00', 'data' => '2026-05-10', '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $casoEncerrado->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $liquidacoes = static::getContainer()->get(LiquidacaoRepository::class)->doCaso($casoEncerrado);
        self::assertCount(0, $liquidacoes, 'caso encerrado não recebe liquidação');
    }

    #[TestDox('Registrar liquidação sem a capacidade financeira: negado (redirect, não caso)')]
    public function testRegistrarLiquidacaoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/liquidacoes', [
            'registrar_liquidacao' => ['tipo' => 'bem_movel', 'descricaoBem' => 'X', 'valorReconhecido' => '100,00', 'data' => '2026-05-10', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: registrar liquidação em caso de OUTRO tenant devolve 404')]
    public function testRegistrarLiquidacaoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/liquidacoes', [
            'registrar_liquidacao' => ['tipo' => 'bem_movel', 'descricaoBem' => 'X', 'valorReconhecido' => '100,00', 'data' => '2026-05-10', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: registrar liquidação não persiste')]
    public function testRegistrarLiquidacaoCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/liquidacoes', [
            'registrar_liquidacao' => ['tipo' => 'bem_movel', 'descricaoBem' => 'MARCADOR CSRF', 'valorReconhecido' => '100,00', 'data' => '2026-05-10', '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $liquidacoes = static::getContainer()->get(LiquidacaoRepository::class)->doCaso($caso);
        self::assertCount(0, $liquidacoes, 'CSRF inválido não persiste liquidação');
    }
}
