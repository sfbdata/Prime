<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Abertura de Caso de Cobrança a partir de um Objeto (Onda 8B-E). Cobre gate módulo + capacidade,
 * CSRF, anti-IDOR (404), o erro de domínio (caso ativo já existe no modo A) e o happy path com
 * redirecionamento ao caso recém-criado.
 */
#[CoversClass(CarteiraController::class)]
final class AbrirCasoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Abrir caso: happy path cria o caso e redireciona ao detalhe do caso')]
    public function testAbrirHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $objetoId = (int) $objeto->getId();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        $token = $this->tokenDoFormulario($crawler, 'abrir_caso');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/casos', [
            'abrir_caso' => ['pessoaCobradaId' => (string) $pessoa->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#/cobrancas/casos/\d+$#', (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $objetoRef = $em->find(ObjetoCobranca::class, $objetoId);
        self::assertCount(1, $em->getRepository(CasoCobranca::class)->findBy(['objeto' => $objetoRef]));
    }

    #[TestDox('Abrir caso no modo A com caso ativo: erro de domínio, volta à carteira sem novo caso')]
    public function testAbrirCasoAtivoJaExiste(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant); // objeto já tem caso ativo (modo Único)
        $objetoId = (int) $caso->getObjeto()->getId();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        $token = $this->tokenDoFormulario($crawler, 'abrir_caso');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/casos', [
            'abrir_caso' => ['pessoaCobradaId' => (string) $pessoa->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteira->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $objetoRef = $em->find(ObjetoCobranca::class, $objetoId);
        self::assertCount(1, $em->getRepository(CasoCobranca::class)->findBy(['objeto' => $objetoRef]));
    }

    #[TestDox('Abrir caso sem a capacidade: negado, nenhum caso é criado')]
    public function testAbrirSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [$carteira] = $this->semearGrafo($tenant);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $objetoId = (int) $objeto->getId();

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/casos', [
            'abrir_caso' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $objetoRef = $em->find(ObjetoCobranca::class, $objetoId);
        self::assertCount(0, $em->getRepository(CasoCobranca::class)->findBy(['objeto' => $objetoRef]));
    }

    #[TestDox('IDOR: abrir caso em objeto de OUTRO tenant devolve 404')]
    public function testAbrirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId() . '/casos', [
            'abrir_caso' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: abrir caso não cria nada')]
    public function testAbrirCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $objetoId = (int) $objeto->getId();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/casos', [
            'abrir_caso' => ['pessoaCobradaId' => (string) $pessoa->getId(), '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteira->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $objetoRef = $em->find(ObjetoCobranca::class, $objetoId);
        self::assertCount(0, $em->getRepository(CasoCobranca::class)->findBy(['objeto' => $objetoRef]));
    }
}
