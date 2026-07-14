<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\AcaoCobrancaController;
use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusProximaAcao;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Tests\Factory\Cobranca\ProximaAcaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de próxima ação e tentativa (Onda 8B). Gate módulo + capacidade
 * `resources.cobranca.gerenciar`, CSRF, anti-IDOR (404), erro de domínio (máx. 1 ação pendente)
 * e happy path com persistência.
 */
#[CoversClass(AcaoCobrancaController::class)]
#[CoversClass(CasoController::class)]
final class AcaoMutacaoControllerTest extends CobrancaWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Definir próxima ação: happy path cria a ação pendente')]
    public function testDefinirHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'definir_proxima_acao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/proxima-acao', [
            'definir_proxima_acao' => ['descricao' => 'Ligar amanhã ZZZ', 'prazo' => '2026-08-20', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $client->followRedirect();
        self::assertStringContainsString('Ligar amanhã ZZZ', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Definir ação quando já há uma pendente: erro de domínio, não cria a segunda')]
    public function testDefinirDuplicada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        ProximaAcaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Ação original']);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'definir_proxima_acao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/proxima-acao', [
            'definir_proxima_acao' => ['descricao' => 'SEGUNDA ACAO WWW', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $client->followRedirect();
        self::assertStringNotContainsString('SEGUNDA ACAO WWW', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Definir ação sem capacidade: negado')]
    public function testDefinirSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/proxima-acao', [
            'definir_proxima_acao' => ['descricao' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: definir ação em caso de outro tenant → 404')]
    public function testDefinirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/proxima-acao', [
            'definir_proxima_acao' => ['descricao' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: definir ação não cria')]
    public function testDefinirCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/proxima-acao', [
            'definir_proxima_acao' => ['descricao' => 'CSRF RUIM VVV', '_token' => 'falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $client->followRedirect();
        self::assertStringNotContainsString('CSRF RUIM VVV', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Concluir ação: happy path muda o status para concluída')]
    public function testConcluirHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acao = ProximaAcaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusProximaAcao::Pendente])->_real();
        $acaoId = (int) $acao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'concluir_acao');

        $client->request('POST', '/cobrancas/acoes/' . $acaoId . '/concluir', [
            'concluir_acao' => ['resultado' => 'Falou com o devedor', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $this->em()->clear();
        self::assertSame(StatusProximaAcao::Concluida, $this->em()->find(ProximaAcao::class, $acaoId)->getStatus());
    }

    #[TestDox('IDOR: concluir ação de outro tenant → 404')]
    public function testConcluirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acaoAlheia = ProximaAcaoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();

        $client->request('POST', '/cobrancas/acoes/' . $acaoAlheia->getId() . '/concluir', [
            'concluir_acao' => ['resultado' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Registrar tentativa: happy path grava evento de histórico')]
    public function testTentativaHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_tentativa_cobranca');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/tentativas', [
            'registrar_tentativa_cobranca' => ['valorSolicitado' => '300,00', 'observacao' => 'Prometeu pagar', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        $eventos = static::getContainer()->get(EventoHistoricoRepository::class)->doCaso($casoFresh);
        self::assertGreaterThanOrEqual(1, count($eventos), 'a tentativa deve gravar ao menos um evento de histórico');
    }

    #[TestDox('IDOR: registrar tentativa em caso de outro tenant → 404')]
    public function testTentativaCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/tentativas', [
            'registrar_tentativa_cobranca' => ['observacao' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
