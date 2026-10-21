<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Entity\ClienteHistorico;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Repository\ClienteHistoricoRepository;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Consulta da aba Histórico do cadastro (E12 3d): escritório explícito, só o cliente pedido, mais nova primeiro e o
 * limite. Roda sem o TenantFilter (KernelTestCase), então o escritório na consulta é o que isola.
 */
#[CoversClass(ClienteHistoricoRepository::class)]
final class ClienteHistoricoRepositoryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ClienteHistoricoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(ClienteHistoricoRepository::class);
    }

    #[TestDox('mais nova primeiro; no mesmo segundo, a gravada por último vem antes')]
    public function testOrdem(): void
    {
        [$tenant, $cliente] = $this->clienteNovo();
        $this->linha($tenant, $cliente, 'segunda', '2026-10-02 10:00:00');
        $this->linha($tenant, $cliente, 'primeira', '2026-10-01 10:00:00');
        $this->linha($tenant, $cliente, 'terceira', '2026-10-03 10:00:00');
        $this->linha($tenant, $cliente, 'quarta', '2026-10-03 10:00:00');
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['quarta', 'terceira', 'segunda', 'primeira'], $this->textos($this->repo->ultimosDoCliente($this->recarregar($cliente), $this->em->find(Tenant::class, $tenant->getId()))));
    }

    #[TestDox('guarda tudo e devolve só o limite (as mais novas)')]
    public function testLimite(): void
    {
        [$tenant, $cliente] = $this->clienteNovo();
        for ($i = 1; $i <= 45; ++$i) {
            $this->linha($tenant, $cliente, 'linha ' . $i, sprintf('2026-10-01 10:%02d:00', $i));
        }
        $this->em->flush();

        $linhas = $this->repo->ultimosDoCliente($cliente, $tenant);
        self::assertCount(40, $linhas);
        self::assertSame('linha 45', $linhas[0]->getTexto());
        self::assertSame('linha 6', $linhas[39]->getTexto());
        self::assertCount(2, $this->repo->ultimosDoCliente($cliente, $tenant, 2));
        self::assertSame(45, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_historico WHERE cliente_id = ?', [$cliente->getId()]));
    }

    #[TestDox('só o cliente pedido e só no escritório dele: outro escritório não vê nada')]
    public function testIsolamento(): void
    {
        [$tenantA, $clienteA] = $this->clienteNovo();
        [$tenantB, $clienteB] = $this->clienteNovo();
        $this->linha($tenantA, $clienteA, 'de A', '2026-10-01 10:00:00');
        $this->linha($tenantB, $clienteB, 'de B', '2026-10-01 10:00:00');
        $this->em->flush();

        self::assertSame(['de A'], $this->textos($this->repo->ultimosDoCliente($clienteA, $tenantA)));
        self::assertSame([], $this->repo->ultimosDoCliente($clienteA, $tenantB), 'cliente de A pedido com o escritório B');
    }

    #[TestDox('apagar o cliente apaga o histórico (CASCADE)')]
    public function testCascata(): void
    {
        [$tenant, $cliente] = $this->clienteNovo();
        $this->linha($tenant, $cliente, 'x', '2026-10-01 10:00:00');
        $this->em->flush();
        $conn = $this->em->getConnection();

        $conn->executeStatement('DELETE FROM cliente_pf WHERE id = ?', [$cliente->getId()]);
        $conn->executeStatement('DELETE FROM cliente WHERE id = ?', [$cliente->getId()]);

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM cliente_historico WHERE cliente_id = ?', [$cliente->getId()]));
    }

    /** @return array{0: Tenant, 1: ClientePF} */
    private function clienteNovo(): array
    {
        $tenant = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();

        return [$tenant, $cliente];
    }

    private function linha(Tenant $tenant, ClientePF $cliente, string $texto, string $em): void
    {
        $h = new ClienteHistorico($tenant, $cliente, null, $texto);
        (new \ReflectionProperty($h, 'em'))->setValue($h, new \DateTimeImmutable($em));
        $this->repo->registrar($h);
    }

    private function recarregar(ClientePF $cliente): ClientePF
    {
        $c = $this->em->find(ClientePF::class, $cliente->getId());
        \assert($c instanceof ClientePF);

        return $c;
    }

    /**
     * @param list<ClienteHistorico> $linhas
     *
     * @return list<string>
     */
    private function textos(array $linhas): array
    {
        return array_map(static fn (ClienteHistorico $h): string => $h->getTexto(), $linhas);
    }
}
