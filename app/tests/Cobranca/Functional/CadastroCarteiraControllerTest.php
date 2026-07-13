<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\ModoCarteira;
use App\Tests\Factory\Cliente\ClientePFFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de cadastro da Carteira (Onda 8B-E): criar carteira, editar configuração e criar objeto.
 * Cobre gate módulo + capacidade, CSRF, anti-IDOR (404 cross-tenant) e o happy path com estado no DB.
 */
#[CoversClass(CarteiraController::class)]
final class CadastroCarteiraControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Criar carteira: happy path persiste a nova carteira do tenant')]
    public function testCriarCarteiraHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas');
        $token = $this->tokenDoFormulario($crawler, 'criar_carteira');

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => [
                'nome' => 'Carteira Nova Teste',
                'clienteId' => (string) $cliente->getId(),
                'modo' => 'unico',
                'formaHonorarios' => 'sem_percentual',
                'toleranciaAtrasoDias' => '0',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Nova Teste']));
    }

    #[TestDox('Criar carteira: percentual em pt-BR (vírgula) é gravado no formato decimal')]
    public function testCriarCarteiraPercentualComVirgula(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas');
        $token = $this->tokenDoFormulario($crawler, 'criar_carteira');

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => [
                'nome' => 'Carteira Percentual Virgula',
                'clienteId' => (string) $cliente->getId(),
                'modo' => 'unico',
                'formaHonorarios' => 'acrescido_divida',
                'percentualHonorarios' => '10,50',
                'toleranciaAtrasoDias' => '0',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $carteiras = $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Percentual Virgula']);
        self::assertCount(1, $carteiras);
        self::assertSame('10.50', $carteiras[0]->getPercentualHonorarios());
    }

    #[TestDox('Criar carteira sem a capacidade: negado, nada é criado')]
    public function testCriarCarteiraSemCapacidade(): void
    {
        $client = static::createClient();
        $this->criarOperadorSemCapacidade($client);

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => ['nome' => 'Carteira Bloqueada', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Bloqueada']));
    }

    #[TestDox('Criar carteira com CSRF inválido: não persiste')]
    public function testCriarCarteiraCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => [
                'nome' => 'Carteira Token Falso',
                'clienteId' => (string) $cliente->getId(),
                'modo' => 'unico',
                'formaHonorarios' => 'sem_percentual',
                '_token' => 'token-falso',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Token Falso']));
    }

    #[TestDox('Editar configuração: happy path muda modo e tolerância')]
    public function testConfigurarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_carteira');

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/configuracao', [
            'editar_configuracao_carteira' => [
                'modo' => 'multiplo',
                'formaHonorarios' => 'sem_percentual',
                'toleranciaAtrasoDias' => '5',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Carteira::class, $carteiraId);
        self::assertSame(ModoCarteira::Multiplo, $recarregada->getModo());
        self::assertSame(5, $recarregada->getToleranciaAtrasoDias());
    }

    #[TestDox('Editar configuração: percentual em pt-BR (vírgula) é gravado no formato decimal')]
    public function testConfigurarPercentualComVirgula(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_carteira');

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/configuracao', [
            'editar_configuracao_carteira' => [
                'modo' => 'unico',
                'formaHonorarios' => 'retido_recuperado',
                'percentualHonorarios' => '12,75',
                'toleranciaAtrasoDias' => '0',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Carteira::class, $carteiraId);
        self::assertSame('12.75', $recarregada->getPercentualHonorarios());
    }

    #[TestDox('IDOR: configurar carteira de OUTRO tenant devolve 404')]
    public function testConfigurarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [$carteiraAlheia] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraAlheia->getId() . '/configuracao', [
            'editar_configuracao_carteira' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Criar objeto: happy path persiste o objeto na carteira')]
    public function testCriarObjetoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'criar_objeto');

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/objetos', [
            'criar_objeto' => ['identificacao' => 'Apto 101 Teste', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(ObjetoCobranca::class)->findBy(['identificacao' => 'Apto 101 Teste']));
    }

    #[TestDox('Criar objeto sem a capacidade: negado, nada é criado')]
    public function testCriarObjetoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [$carteira] = $this->semearGrafo($tenant);

        $client->request('POST', '/cobrancas/carteiras/' . $carteira->getId() . '/objetos', [
            'criar_objeto' => ['identificacao' => 'Objeto Bloqueado', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(ObjetoCobranca::class)->findBy(['identificacao' => 'Objeto Bloqueado']));
    }
}
