<?php

declare(strict_types=1);

namespace App\Cliente\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As chaves do "Envio automático" por cliente (spec push-envio-automatico §1). Toda consulta leva o
 * tenant explícito: a captação e o comando semanal rodam no CLI, sem TenantFilter.
 *
 * @extends ServiceEntityRepository<EnvioAutomaticoDoCliente>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class EnvioAutomaticoDoClienteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvioAutomaticoDoCliente::class);
    }

    public function salvar(EnvioAutomaticoDoCliente $linha, bool $flush = false): void
    {
        $this->getEntityManager()->persist($linha);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function doClienteDoTenant(Cliente $cliente, Tenant $tenant): ?EnvioAutomaticoDoCliente
    {
        return $this->findOneBy(['cliente' => $cliente, 'tenant' => $tenant]);
    }

    /**
     * A linha do cliente, criada com os padrões (D1: tudo desligado) se ainda não existe. O INSERT é
     * `ON CONFLICT (cliente_id) DO NOTHING`: na corrida da primeira gravação o segundo não estoura o
     * UNIQUE (o que fecharia o EntityManager e viraria 500); os dois releem a mesma linha e seguem.
     */
    public function garantirDoCliente(Cliente $cliente, Tenant $tenant, User $por, \DateTimeImmutable $em): EnvioAutomaticoDoCliente
    {
        $existente = $this->doClienteDoTenant($cliente, $tenant);
        if ($existente !== null) {
            return $existente;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO cliente_envio_automatico (tenant_id, cliente_id, novas_movimentacoes, audiencias_intimacoes, resumo_semanal, atualizado_por_id, atualizado_em)
             VALUES (:tenant, :cliente, :padrao, :padrao, :padrao, :por, :em)
             ON CONFLICT (cliente_id) DO NOTHING',
            [
                'tenant' => $tenant->getId(), 'cliente' => $cliente->getId(), 'por' => $por->getId(),
                'em' => $em->format('Y-m-d H:i:s'), 'padrao' => ChaveDoEnvioAutomatico::PADRAO,
            ],
            ['padrao' => 'boolean'],
        );
        $linha = $this->doClienteDoTenant($cliente, $tenant);
        \assert($linha instanceof EnvioAutomaticoDoCliente);

        return $linha;
    }

    /**
     * As linhas dos clientes informados, por id do cliente (cliente sem linha fica de fora: padrões).
     *
     * @param list<int> $clienteIds
     *
     * @return array<int, EnvioAutomaticoDoCliente>
     */
    public function porClientesDoTenant(Tenant $tenant, array $clienteIds): array
    {
        if ($clienteIds === []) {
            return [];
        }

        /** @var list<EnvioAutomaticoDoCliente> $linhas */
        $linhas = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('IDENTITY(e.cliente) IN (:clientes)')
            ->setParameter('tenant', $tenant)
            ->setParameter('clientes', array_values(array_unique($clienteIds)))
            ->getQuery()
            ->getResult();

        $porCliente = [];
        foreach ($linhas as $linha) {
            $porCliente[(int) $linha->getCliente()->getId()] = $linha;
        }

        return $porCliente;
    }
}
