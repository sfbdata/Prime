<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\TipoVinculo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 4 do ajuste 2: "Nova pessoa" dentro do objeto (aba Pessoas). Cadastra a pessoa e a vincula ao
 * objeto num passo só. Gate capacidade `resources.cobranca.gerenciar`, CSRF, anti-IDOR (404).
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoPessoaControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Nova pessoa: cadastra + vincula ao objeto e aparece na aba Pessoas')]
    public function testCriaPessoaVinculadaHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => [
                'nome' => 'Avalista Teste ZZZ',
                'tipoVinculo' => TipoVinculo::Representante->value,
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Avalista Teste ZZZ', $html);
        self::assertStringContainsString('Representante', $html);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vinculos = $em->getRepository(VinculoPessoaObjeto::class)->findBy(['objeto' => $objetoId]);
        self::assertCount(1, $vinculos);
        self::assertSame('Avalista Teste ZZZ', $vinculos[0]->getPessoa()?->getNome());
        self::assertSame(TipoVinculo::Representante, $vinculos[0]->getTipoVinculo());
    }

    #[TestDox('Nova pessoa sem nome: nada é criado')]
    public function testNomeObrigatorio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'criar_pessoa_vinculada');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => ['nome' => '', 'tipoVinculo' => TipoVinculo::Outro->value, '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(VinculoPessoaObjeto::class)->findBy(['objeto' => $objetoId]));
    }

    #[TestDox('IDOR: nova pessoa em objeto de outro escritório → 404')]
    public function testCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId() . '/pessoas', [
            'criar_pessoa_vinculada' => ['nome' => 'X', 'tipoVinculo' => TipoVinculo::Outro->value, '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem capacidade: negado, nada é criado')]
    public function testSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/pessoas', [
            'criar_pessoa_vinculada' => ['nome' => 'Bloqueado', 'tipoVinculo' => TipoVinculo::Outro->value, '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(VinculoPessoaObjeto::class)->findBy(['objeto' => $objetoId]));
    }
}
