<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O guarda de escritório DO REPOSITÓRIO, com o TenantFilter global DESLIGADO.
 *
 * Existe porque o teste de tela não prova isto: com o filtro ligado — e ele está ligado em
 * toda requisição HTTP —, apagar o `andWhere('m.tenant = :tenant')` do repositório não
 * quebra teste nenhum (medido por reintrodução). O filtro global cobriria.
 *
 * Só que o filtro NÃO está ligado onde não há requisição: comando de console, worker,
 * consumidor de fila. Nesses caminhos quem segura é o repositório, sozinho. É esse guarda
 * que este arquivo prova — e é o único lugar do conjunto onde a prova é possível.
 */
#[CoversClass(PastaChecklistModeloRepository::class)]
final class PastaChecklistModeloRepositoryTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Garante que o TenantFilter não está ligado. Sem isto o teste passaria pelo motivo
     * errado — e continuaria passando com o repositório furado.
     */
    private function desligarTenantFilter(): void
    {
        $filtros = $this->em()->getFilters();

        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }

        self::assertFalse($filtros->isEnabled('tenant'), 'o filtro global precisa estar OFF para este teste valer');
    }

    private function criarTenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param string[] $titulos */
    private function criarModelo(Tenant $tenant, string $nome, array $titulos): PastaChecklistModelo
    {
        $modelo = new PastaChecklistModelo();
        $modelo->setTenant($tenant);
        $modelo->setNome($nome);

        $ordem = 1;
        foreach ($titulos as $titulo) {
            $linha = new PastaChecklistModeloItem();
            $linha->setTenant($tenant);
            $linha->setTitulo($titulo);
            $linha->setOrdem($ordem);
            $modelo->adicionarItem($linha);
            ++$ordem;
        }

        $this->em()->persist($modelo);
        $this->em()->flush();

        return $modelo;
    }

    #[TestDox('listarDoTenant não devolve modelo de outro escritório nem com o filtro global desligado')]
    public function testListarNaoVazaSemOFiltroGlobal(): void
    {
        self::bootKernel();
        $this->desligarTenantFilter();

        $tenantA = $this->criarTenant('Escritório A ');
        $tenantB = $this->criarTenant('Escritório B ');
        $this->criarModelo($tenantA, 'Meu modelo', ['Procuração']);
        $this->criarModelo($tenantB, 'Do vizinho', ['Segredo do vizinho']);

        $repo    = static::getContainer()->get(PastaChecklistModeloRepository::class);
        $modelos = $repo->listarDoTenant($tenantA);

        self::assertCount(1, $modelos);
        self::assertSame('MEU MODELO', $modelos[0]->getNome());
    }

    #[TestDox('buscarDoTenant devolve null para modelo de outro escritório, sem o filtro global')]
    public function testBuscarDoTenantNaoAlcancaOVizinho(): void
    {
        self::bootKernel();
        $this->desligarTenantFilter();

        $tenantA   = $this->criarTenant('Escritório A ');
        $tenantB   = $this->criarTenant('Escritório B ');
        $doVizinho = $this->criarModelo($tenantB, 'Do vizinho', ['Procuração']);

        $repo = static::getContainer()->get(PastaChecklistModeloRepository::class);

        self::assertNull($repo->buscarDoTenant((int) $doVizinho->getId(), $tenantA));
        self::assertNotNull(
            $repo->buscarDoTenant((int) $doVizinho->getId(), $tenantB),
            'para o dono dele, o mesmo id continua achável — senão o teste acima passaria por engano',
        );
    }

    #[TestDox('buscarPorNome não enxerga o nome usado por outro escritório')]
    public function testBuscarPorNomeNaoAlcancaOVizinho(): void
    {
        self::bootKernel();
        $this->desligarTenantFilter();

        $tenantA = $this->criarTenant('Escritório A ');
        $tenantB = $this->criarTenant('Escritório B ');
        $this->criarModelo($tenantB, 'Trabalhista', ['Procuração']);

        $repo = static::getContainer()->get(PastaChecklistModeloRepository::class);

        self::assertNull(
            $repo->buscarPorNome('Trabalhista', $tenantA),
            'o mesmo nome tem de ficar livre para cada escritório',
        );
        self::assertNotNull($repo->buscarPorNome('trabalhista', $tenantB), 'e continua achável para o dono');
    }
}
