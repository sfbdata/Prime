<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * C3 — cpf/cnpj de Cliente passam a ser únicos POR ESCRITÓRIO (não mais global).
 *
 * Duas garantias verificadas:
 *  (a) BANCO: removido o unique global (migration Version20260626150000) → a mesma pessoa/empresa
 *      pode ser cliente de 2 escritórios (antes o INSERT cross-tenant batia no unique global).
 *  (b) APLICAÇÃO: #[UniqueEntity(['cpf','tenant'])]/['cnpj','tenant'] rejeita duplicata DENTRO do
 *      mesmo escritório, mas NÃO entre escritórios. Roda com o TenantFilter DESLIGADO (KernelTestCase)
 *      = pior caso: prova que o escopo vem do campo `tenant` explícito, não do filtro de sessão.
 */
final class ClienteUnicidadePorTenantTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em        = static::getContainer()->get(EntityManagerInterface::class);
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    // ---------------------------------------------------------------- PF (cpf)

    #[TestDox('Banco aceita o mesmo CPF em escritórios diferentes (unique global removido)')]
    public function testMesmoCpfEmTenantsDiferentesEhPermitidoNoBanco(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $cpf = '11122233344';

        $a = $this->persistir($this->novaClientePF($tenantA, $cpf));
        $b = $this->persistir($this->novaClientePF($tenantB, $cpf));

        self::assertNotNull($a->getId());
        self::assertNotNull($b->getId());
        self::assertNotSame($a->getId(), $b->getId(), 'são dois cadastros independentes');
    }

    #[TestDox('Validação rejeita o mesmo CPF dentro do mesmo escritório')]
    public function testMesmoCpfNoMesmoTenantEhRejeitado(): void
    {
        $tenantA = $this->criarTenant();
        $cpf = '55566677788';
        $this->persistir($this->novaClientePF($tenantA, $cpf));

        $duplicado = $this->novaClientePF($tenantA, $cpf); // mesmo cpf, mesmo tenant, não persistido

        self::assertGreaterThan(0, $this->violacoesNoCampo($duplicado, 'cpf'), 'CPF duplicado no mesmo tenant deveria violar');
    }

    #[TestDox('Validação NÃO rejeita o mesmo CPF em escritório diferente (escopo por tenant)')]
    public function testMesmoCpfEmTenantDiferenteNaoViola(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $cpf = '99988877766';
        $this->persistir($this->novaClientePF($tenantA, $cpf));

        $outroTenant = $this->novaClientePF($tenantB, $cpf); // mesmo cpf, OUTRO tenant

        self::assertSame(0, $this->violacoesNoCampo($outroTenant, 'cpf'), 'CPF em outro tenant NÃO deveria violar');
    }

    // ---------------------------------------------------------------- PJ (cnpj)

    #[TestDox('Banco aceita o mesmo CNPJ em escritórios diferentes (unique global removido)')]
    public function testMesmoCnpjEmTenantsDiferentesEhPermitidoNoBanco(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $cnpj = '11222333000144';

        $a = $this->persistir($this->novaClientePJ($tenantA, $cnpj));
        $b = $this->persistir($this->novaClientePJ($tenantB, $cnpj));

        self::assertNotNull($a->getId());
        self::assertNotNull($b->getId());
        self::assertNotSame($a->getId(), $b->getId(), 'são dois cadastros independentes');
    }

    #[TestDox('Validação rejeita o mesmo CNPJ dentro do mesmo escritório')]
    public function testMesmoCnpjNoMesmoTenantEhRejeitado(): void
    {
        $tenantA = $this->criarTenant();
        $cnpj = '55666777000188';
        $this->persistir($this->novaClientePJ($tenantA, $cnpj));

        $duplicado = $this->novaClientePJ($tenantA, $cnpj);

        self::assertGreaterThan(0, $this->violacoesNoCampo($duplicado, 'cnpj'), 'CNPJ duplicado no mesmo tenant deveria violar');
    }

    #[TestDox('Validação NÃO rejeita o mesmo CNPJ em escritório diferente (escopo por tenant)')]
    public function testMesmoCnpjEmTenantDiferenteNaoViola(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $cnpj = '99888777000166';
        $this->persistir($this->novaClientePJ($tenantA, $cnpj));

        $outroTenant = $this->novaClientePJ($tenantB, $cnpj);

        self::assertSame(0, $this->violacoesNoCampo($outroTenant, 'cnpj'), 'CNPJ em outro tenant NÃO deveria violar');
    }

    // ----------------------------------------------------------------- helpers

    private function violacoesNoCampo(object $entity, string $campo): int
    {
        $total = 0;
        foreach ($this->validator->validate($entity) as $violacao) {
            if ($violacao->getPropertyPath() === $campo) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function persistir(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant UNICIDADE ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function novaClientePF(Tenant $tenant, string $cpf): ClientePF
    {
        $n = ++$this->seq;

        $cliente = new ClientePF();
        $cliente->setNomeCompleto('PF' . $n . substr(uniqid(), -6));
        $cliente->setCpf($cpf);
        $cliente->setRg('RG' . $n);
        $cliente->setRgOrgaoExpedidor('SSP/SP');
        $cliente->setEmail('pf_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);

        return $cliente;
    }

    private function novaClientePJ(Tenant $tenant, string $cnpj): ClientePJ
    {
        $n = ++$this->seq;

        $cliente = new ClientePJ();
        $cliente->setRazaoSocial('PJ' . $n . substr(uniqid(), -6));
        $cliente->setCnpj($cnpj);
        $cliente->setEnderecSede('Av. Teste, ' . $n);
        $cliente->setRepresentanteLegal('Representante ' . $n);
        $cliente->setRepresentanteCpf(str_pad((string) ($n + 700), 11, '0', STR_PAD_LEFT));
        $cliente->setRepresentanteRg('RG' . $n);
        $cliente->setRepresentanteCargo('Diretor');
        $cliente->setEmail('pj_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);

        return $cliente;
    }
}
