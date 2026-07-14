<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\PessoaController;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\TipoVinculo;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de Vínculo geridas DENTRO do objeto (ajuste 2): vincular pessoa existente e encerrar vínculo.
 * Os formulários vivem na aba Pessoas da página do objeto e as ações redirecionam de volta para ela.
 * Cobre gate módulo + capacidade, CSRF, anti-IDOR (404 cross-tenant) e erro de domínio (já encerrado).
 */
#[CoversClass(PessoaController::class)]
final class CadastroPessoaVinculoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Vincular pessoa existente ao objeto: happy path cria o vínculo e volta ao objeto')]
    public function testVincularHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'vincular_pessoa_a_objeto');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/vinculos', [
            'vincular_pessoa_a_objeto' => [
                'pessoaId' => (string) $pessoa->getId(),
                'tipoVinculo' => 'proprietario',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

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

    #[TestDox('Encerrar vínculo: happy path define a data final e volta ao objeto')]
    public function testEncerrarVinculoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();
        $vinculoId = $this->semearVinculoAberto($tenant, $caso->getObjeto());

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'encerrar_vinculo');

        $client->request('POST', '/cobrancas/vinculos/' . $vinculoId . '/encerrar', [
            'encerrar_vinculo' => ['motivoEncerramento' => 'Venda do imóvel', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(VinculoPessoaObjeto::class, $vinculoId)->getDataFim());
    }

    #[TestDox('Encerrar vínculo já encerrado: erro de domínio, permanece encerrado sem sobrescrever')]
    public function testEncerrarVinculoJaEncerrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();
        $vinculoId = $this->semearVinculoAberto($tenant, $caso->getObjeto(), new \DateTimeImmutable('2026-01-10'));

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'encerrar_vinculo');

        $client->request('POST', '/cobrancas/vinculos/' . $vinculoId . '/encerrar', [
            'encerrar_vinculo' => ['motivoEncerramento' => 'Nova tentativa', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

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
