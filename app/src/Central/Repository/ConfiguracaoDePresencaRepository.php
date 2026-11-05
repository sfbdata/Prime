<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Entity\Presenca;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A regra de presença do escritório (fase 3). Tenant explícito em toda consulta (regra 4 da Central).
 *
 * O limite de ausência é lido em toda página (o cabeçalho e o lançador da Central): fica guardado por escritório até
 * o fim da requisição (spec memoria-do-layout). Quem grava passa por {@see salvar()}, que esquece o guardado;
 * `ResetInterface` (tag `kernel.reset`) zera entre requisições e entre mensagens do worker.
 *
 * @extends ServiceEntityRepository<ConfiguracaoDePresenca>
 */
class ConfiguracaoDePresencaRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<int, int> minutos por id de escritório */
    private array $minutosGuardados = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDePresenca::class);
    }

    public function doEscritorio(Tenant $tenant): ?ConfiguracaoDePresenca
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O limite do escritório, em minutos; sem escolha, o padrão do desenho (60). */
    public function minutosAusente(Tenant $tenant): int
    {
        $id = $tenant->getId();
        if ($id === null) {
            return $this->doEscritorio($tenant)?->getMinutosAusente() ?? Presenca::INATIVO_APOS_MINUTOS;
        }

        return $this->minutosGuardados[$id] ??= $this->doEscritorio($tenant)?->getMinutosAusente() ?? Presenca::INATIVO_APOS_MINUTOS;
    }

    public function salvar(ConfiguracaoDePresenca $configuracao): void
    {
        // Esquece antes de gravar: se a gravação falhar, a próxima leitura volta ao banco.
        $this->minutosGuardados = [];
        $this->getEntityManager()->persist($configuracao);
        $this->getEntityManager()->flush();
    }

    public function reset(): void
    {
        $this->minutosGuardados = [];
    }
}
