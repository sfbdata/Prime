<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\PessoaController;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\TipoVinculo;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de Pessoa e Vínculo (Onda 8B-E): criar pessoa, vincular pessoa a objeto e encerrar vínculo.
 * Cobre gate módulo + capacidade, CSRF, anti-IDOR (404 cross-tenant), erro de domínio (vínculo já
 * encerrado) e o happy path com estado no DB.
 */
#[CoversClass(PessoaController::class)]
final class CadastroPessoaVinculoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Criar pessoa: happy path persiste e volta à origem (carteira)')]
    public function testCriarPessoaHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $origem = '/cobrancas/carteiras/' . $carteira->getId();

        // O BrowserKit usa a própria página como Referer (mesmo host) — exigido pelo CSRF stateless
        // (valida same-origin por Origin/Referer). redirecionarParaOrigem volta ao Referer interno.
        $crawler = $client->request('GET', $origem);
        $token = $this->tokenDoFormulario($crawler, 'criar_pessoa');

        $client->request('POST', '/cobrancas/pessoas', [
            'criar_pessoa' => ['nome' => 'Fulano de Cadastro', '_token' => $token],
        ]);

        self::assertResponseRedirects('http://localhost' . $origem);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(Pessoa::class)->findBy(['nome' => 'Fulano de Cadastro']));
    }

    #[TestDox('Criar pessoa: com referer interno, volta para a tela de origem')]
    public function testCriarPessoaVoltaAoReferer(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $origem = '/cobrancas/carteiras/' . $carteira->getId();

        $crawler = $client->request('GET', $origem);
        $token = $this->tokenDoFormulario($crawler, 'criar_pessoa');

        $client->request('POST', '/cobrancas/pessoas', [
            'criar_pessoa' => ['nome' => 'Beltrano Origem', '_token' => $token],
        ], [], ['HTTP_REFERER' => 'http://localhost' . $origem]);

        self::assertResponseRedirects('http://localhost' . $origem);
    }

    #[TestDox('Criar pessoa sem a capacidade: negado, nada é criado')]
    public function testCriarPessoaSemCapacidade(): void
    {
        $client = static::createClient();
        $this->criarOperadorSemCapacidade($client);

        $client->request('POST', '/cobrancas/pessoas', [
            'criar_pessoa' => ['nome' => 'Pessoa Bloqueada', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(Pessoa::class)->findBy(['nome' => 'Pessoa Bloqueada']));
    }

    #[TestDox('Vincular pessoa a objeto: happy path cria o vínculo')]
    public function testVincularHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        $token = $this->tokenDoFormulario($crawler, 'vincular_pessoa_a_objeto');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/vinculos', [
            'vincular_pessoa_a_objeto' => [
                'pessoaId' => (string) $pessoa->getId(),
                'tipoVinculo' => 'proprietario',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteira->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(VinculoPessoaObjeto::class)->findAll());
    }

    #[TestDox('IDOR: vincular em objeto de OUTRO tenant devolve 404')]
    public function testVincularCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId() . '/vinculos', [
            'vincular_pessoa_a_objeto' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Vincular sem a capacidade: negado, nada é criado')]
    public function testVincularSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);

        $client->request('POST', '/cobrancas/objetos/' . $caso->getObjeto()->getId() . '/vinculos', [
            'vincular_pessoa_a_objeto' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(VinculoPessoaObjeto::class)->findAll());
    }

    #[TestDox('Encerrar vínculo: happy path define a data final')]
    public function testEncerrarVinculoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $vinculoId = $this->semearVinculoAberto($tenant, $caso->getObjeto());

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        $token = $this->tokenDoFormulario($crawler, 'encerrar_vinculo');

        $client->request('POST', '/cobrancas/vinculos/' . $vinculoId . '/encerrar', [
            'encerrar_vinculo' => ['motivoEncerramento' => 'Venda do imóvel', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteira->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(VinculoPessoaObjeto::class, $vinculoId)->getDataFim());
    }

    #[TestDox('Encerrar vínculo já encerrado: erro de domínio, permanece encerrado sem sobrescrever')]
    public function testEncerrarVinculoJaEncerrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $vinculoId = $this->semearVinculoAberto($tenant, $caso->getObjeto(), new \DateTimeImmutable('2026-01-10'));

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        $token = $this->tokenDoFormulario($crawler, 'encerrar_vinculo');

        $client->request('POST', '/cobrancas/vinculos/' . $vinculoId . '/encerrar', [
            'encerrar_vinculo' => ['motivoEncerramento' => 'Nova tentativa', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteira->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('2026-01-10', $em->find(VinculoPessoaObjeto::class, $vinculoId)->getDataFim()->format('Y-m-d'));
    }

    #[TestDox('IDOR: encerrar vínculo de OUTRO tenant devolve 404')]
    public function testEncerrarVinculoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outro = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outro);
        $vinculoId = $this->semearVinculoAberto($outro, $casoAlheio->getObjeto());

        $client->request('POST', '/cobrancas/vinculos/' . $vinculoId . '/encerrar', [
            'encerrar_vinculo' => ['motivoEncerramento' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Cria um vínculo (aberto por padrão; encerrado se `$dataFim` vier) diretamente no banco, para os
     * cenários de encerramento. Retorna o id.
     */
    private function semearVinculoAberto(Tenant $tenant, \App\Cobranca\Entity\ObjetoCobranca $objeto, ?\DateTimeImmutable $dataFim = null): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant])->_real();

        $vinculo = new VinculoPessoaObjeto();
        $vinculo->setTenant($tenant);
        $vinculo->setPessoa($pessoa);
        $vinculo->setObjeto($objeto);
        $vinculo->setTipoVinculo(TipoVinculo::Proprietario);
        $vinculo->setDataInicio(new \DateTimeImmutable('2026-01-01'));
        if ($dataFim !== null) {
            $vinculo->setDataFim($dataFim);
            $vinculo->setMotivoEncerramento('Encerrado antes');
        }
        $em->persist($vinculo);
        $em->flush();

        return (int) $vinculo->getId();
    }
}
