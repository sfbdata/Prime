<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de nível de Caso (Onda 8B): encerrar. Cobre gate módulo + capacidade, CSRF, anti-IDOR
 * (404), a invariável 17 (só encerra com saldo exigível 0) e o happy path.
 */
#[CoversClass(CasoController::class)]
final class CasoMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Encerrar caso com saldo zero: happy path muda o status')]
    public function testEncerrarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant); // sem obrigações → saldo exigível 0
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'encerrar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/encerrar', [
            'encerrar_caso' => ['observacao' => 'Quitado', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Encerrado, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('Encerrar caso com saldo exigível > 0: bloqueado (invariável 17), permanece ativo')]
    public function testEncerrarSaldoNaoResolvido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 50000, 'encargosReconhecidos' => 0]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'encerrar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/encerrar', [
            'encerrar_caso' => ['_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus(), 'não pode encerrar com saldo exigível > 0');
    }

    #[TestDox('Encerrar sem a capacidade: negado (redirect, não caso)')]
    public function testEncerrarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/encerrar', [
            'encerrar_caso' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('IDOR: encerrar caso de OUTRO tenant devolve 404')]
    public function testEncerrarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/encerrar', [
            'encerrar_caso' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: encerrar não muda o status')]
    public function testEncerrarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/encerrar', [
            'encerrar_caso' => ['_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }
}
