<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Judicialização do caso (Onda 8B, em CasoController). Vincula uma Pasta EXISTENTE do tenant e muda o
 * status para judicializado (sem encerrar). Cobre gate de capacidade `resources.cobranca.gerenciar` +
 * módulo `pastas`, CSRF, anti-IDOR (404 no caso e Pasta de outro tenant → PastaNaoEncontrada), erro de
 * domínio (já judicializado) e o happy path. O admin de teste é `isSystem` → passa o gate de `pastas`.
 */
#[CoversClass(CasoController::class)]
final class JudicializarMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Judicializar: happy path vincula a pasta e muda o status (não encerra)')]
    public function testJudicializarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/casos/' . $casoId);
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['pastaId' => (string) $pasta->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId);
        self::assertSame(StatusCaso::Judicializado, $fresh->getStatus());
        self::assertNotNull($fresh->getPastaJudicial(), 'pasta vinculada ao caso');
    }

    #[TestDox('Judicializar com Pasta de OUTRO tenant: erro de domínio, não judicializa')]
    public function testJudicializarPastaDeOutroTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pastaAlheia = PastaFactory::createOne(['tenant' => $this->tenantAvulso()])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/casos/' . $casoId);
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['pastaId' => (string) $pastaAlheia->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus(), 'pasta cross-tenant não pode judicializar');
    }

    #[TestDox('Judicializar caso já judicializado: erro de domínio, sem novo efeito')]
    public function testJudicializarJaJudicializado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado]);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        // Token colhido de um caso ativo (o judicializado esconde o botão, mas o token é por form/sessão).
        [, $casoAtivo] = $this->semearGrafo($tenant);
        $crawler = $client->request('GET', '/cobrancas/casos/' . $casoAtivo->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['pastaId' => (string) $pasta->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(CasoCobranca::class, $casoId)->getPastaJudicial(), 'não revincula pasta num caso já judicializado');
    }

    #[TestDox('Judicializar sem a capacidade: negado (redirect, não caso)')]
    public function testJudicializarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['pastaId' => '1', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: judicializar caso de OUTRO tenant devolve 404')]
    public function testJudicializarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/judicializar', [
            'judicializar_caso' => ['pastaId' => '1', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: judicializar não muda o status')]
    public function testJudicializarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['pastaId' => (string) $pasta->getId(), '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/casos/' . $casoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }
}
