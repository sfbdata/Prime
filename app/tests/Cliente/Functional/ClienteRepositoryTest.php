<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Repository\ClienteRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida que o TenantFilter isola o domínio Cliente após Cliente/ClienteDocumento
 * virarem TenantAware. Cobre a herança JOINED (a coluna tenant fica só na tabela base
 * `cliente`, mas o filtro precisa escopar PF/PJ e a carga por id) e ClienteDocumento.
 */
#[CoversClass(TenantFilter::class)]
final class ClienteRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Com o filtro ligado, findByFilters só retorna clientes (PF e PJ) do tenant ativo')]
    public function testFiltroIsolaListagemNaHierarquiaJoined(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $this->criarClientePF($tenantA);
        $this->criarClientePJ($tenantA);
        $this->criarClientePF($tenantB);

        /** @var ClienteRepository $repo */
        $repo = $this->em->getRepository(Cliente::class);

        // Sem filtro: vê os 3 (PF e PJ, via discriminator).
        self::assertCount(3, $repo->findByFilters([]));

        // Com filtro para o tenant A: vê só os 2 dele.
        $this->ligarFiltro((int) $tenantA->getId());
        $doA = $repo->findByFilters([]);
        self::assertCount(2, $doA);
        foreach ($doA as $cliente) {
            self::assertSame($tenantA->getId(), $cliente->getTenant()->getId());
        }

        // Trocando para B: vê só o 1 dele.
        $this->ligarFiltro((int) $tenantB->getId());
        self::assertCount(1, $repo->findByFilters([]));

        // Desligando: vê os 3 de novo.
        $this->em->getFilters()->disable('tenant');
        self::assertCount(3, $repo->findByFilters([]));
    }

    #[TestDox('Autocompletes (findAllNomes) só expõem dados do tenant ativo')]
    public function testFiltroIsolaAutocompletes(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $pfA = $this->criarClientePF($tenantA);
        $pfB = $this->criarClientePF($tenantB);

        /** @var ClienteRepository $repo */
        $repo = $this->em->getRepository(Cliente::class);

        $this->ligarFiltro((int) $tenantA->getId());
        $nomes = $repo->findAllNomes();

        self::assertContains($pfA->getNomeCompleto(), $nomes);
        self::assertNotContains($pfB->getNomeCompleto(), $nomes, 'autocomplete vazou nome de outro tenant');
    }

    #[TestDox('find() por id de cliente de outro tenant retorna null (fecha IDOR na herança JOINED)')]
    public function testFindPorIdFechaIdorDoCliente(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $clienteB = $this->criarClientePF($tenantB);
        $idB = (int) $clienteB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull(
            $this->em->find(Cliente::class, $idB),
            'find() de cliente de outro tenant não foi bloqueado pelo filtro (IDOR aberto na herança JOINED)'
        );
    }

    #[TestDox('find() por id de documento de outro tenant retorna null (fecha IDOR de ClienteDocumento)')]
    public function testFindPorIdFechaIdorDoDocumento(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $clienteB = $this->criarClientePF($tenantB);
        $docB = $this->criarDocumento($clienteB);
        $idDocB = (int) $docB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull(
            $this->em->find(ClienteDocumento::class, $idDocB),
            'find() de documento de outro tenant não foi bloqueado pelo filtro'
        );
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant CLI ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarClientePF(Tenant $tenant): ClientePF
    {
        $n = ++$this->seq;
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Cliente PF ' . $n . ' ' . uniqid());
        $cliente->setCpf(str_pad((string) $n, 11, '0', STR_PAD_LEFT));
        $cliente->setRg('123456' . $n);
        $cliente->setRgOrgaoExpedidor('SSP/SP');
        $this->preencherComuns($cliente, $tenant);

        return $cliente;
    }

    private function criarClientePJ(Tenant $tenant): ClientePJ
    {
        $n = ++$this->seq;
        $cliente = new ClientePJ();
        $cliente->setRazaoSocial('Empresa ' . $n . ' ' . uniqid());
        $cliente->setCnpj(str_pad((string) $n, 14, '0', STR_PAD_LEFT));
        $cliente->setEnderecSede('Av. Teste, ' . $n);
        $cliente->setRepresentanteLegal('Representante ' . $n);
        $cliente->setRepresentanteCpf(str_pad((string) ($n + 500), 11, '0', STR_PAD_LEFT));
        $cliente->setRepresentanteRg('RG' . $n);
        $cliente->setRepresentanteCargo('Diretor');
        $this->preencherComuns($cliente, $tenant);

        return $cliente;
    }

    private function preencherComuns(Cliente $cliente, Tenant $tenant): void
    {
        $cliente->setEmail('cliente_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);
        $this->em->persist($cliente);
        $this->em->flush();
    }

    private function criarDocumento(Cliente $cliente): ClienteDocumento
    {
        $doc = new ClienteDocumento();
        $doc->setCliente($cliente);
        $doc->setTenant($cliente->getTenant());
        $doc->setTitulo('Documento ' . uniqid());
        $doc->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO);
        $doc->setCaminhoArquivo('arquivo_' . uniqid() . '.pdf');
        $doc->setNomeOriginal('rg.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }
}
