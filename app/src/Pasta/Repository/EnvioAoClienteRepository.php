<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O histórico de envios ao cliente de UMA pasta (spec push-avisar-cliente §1). Tenant explícito.
 *
 * @extends ServiceEntityRepository<EnvioAoCliente>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class EnvioAoClienteRepository extends ServiceEntityRepository
{
    public const EMAIL_REMOVIDO = 'e-mail removido';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvioAoCliente::class);
    }

    public function salvar(EnvioAoCliente $envio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($envio);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * LGPD: o endereço removido sai das linhas "E-mail cadastrado" do histórico da pasta (vira
     * {@see self::EMAIL_REMOVIDO}). Os envios reais (Análise IA, automático) ficam: são a prova do ato.
     */
    public function anonimizarCadastroDoEmail(Tenant $tenant, Pasta $pasta, string $email): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE envio_ao_cliente SET para = CAST(:anonimo AS json) WHERE tenant_id = :tenant AND pasta_id = :pasta AND tipo = :tipo AND CAST(para AS jsonb) @> CAST(:email AS jsonb)',
            [
                'anonimo' => json_encode([self::EMAIL_REMOVIDO], JSON_THROW_ON_ERROR),
                'tenant' => $tenant->getId(),
                'pasta' => $pasta->getId(),
                'tipo' => TipoDeEnvioAoCliente::Cadastro->value,
                'email' => json_encode([$email], JSON_THROW_ON_ERROR),
            ],
        );
    }

    /** Quantos envios de Análise IA a pasta fez desde o instante (o limite por hora). */
    public function contarEnviosDeAnaliseDesde(Tenant $tenant, Pasta $pasta, \DateTimeImmutable $desde): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('e.em >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('tipo', TipoDeEnvioAoCliente::Ia)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Os destinatários a quem esta análise já foi enviada desde o instante (a idempotência do envio).
     *
     * @return list<string>
     */
    public function destinatariosDaAnaliseDesde(Tenant $tenant, Pasta $pasta, int $analiseId, \DateTimeImmutable $desde): array
    {
        /** @var list<EnvioAoCliente> $envios */
        $envios = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('e.referencia = :analise')
            ->andWhere('e.em >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('tipo', TipoDeEnvioAoCliente::Ia)
            ->setParameter('analise', $analiseId)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getResult();

        return array_values(array_unique(array_merge([], ...array_map(static fn (EnvioAoCliente $e): array => $e->getPara(), $envios))));
    }

    /**
     * Os envios mais recentes da pasta (o "Histórico de envios" mostra 6).
     *
     * @return list<EnvioAoCliente>
     */
    public function recentesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        /** @var list<EnvioAoCliente> $envios */
        $envios = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->orderBy('e.em', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $envios;
    }

    public function contarDaPasta(Tenant $tenant, Pasta $pasta): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Os envios de Análise IA da pasta, do mais antigo ao mais recente (o cartão da análise mostra
     * o último em "Enviado dd/mm às HH:MM" e todos na dica).
     *
     * @return list<EnvioAoCliente>
     */
    public function enviosDeAnalisesDaPasta(Tenant $tenant, Pasta $pasta): array
    {
        /** @var list<EnvioAoCliente> $envios */
        $envios = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->andWhere('e.tipo = :tipo')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('tipo', TipoDeEnvioAoCliente::Ia)
            ->orderBy('e.em', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $envios;
    }
}
