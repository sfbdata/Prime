<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda busca filtra pelo escritório por escrito (a assembleia informada), sem depender do filtro da sessão. A busca por
 * id só devolve a ocorrência da assembleia pedida (guarda de posse).
 *
 * @extends ServiceEntityRepository<OcorrenciaDaUnidade>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class OcorrenciaDaUnidadeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OcorrenciaDaUnidade::class);
    }

    public function salvar(OcorrenciaDaUnidade $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Guarda de posse: a ocorrência pelo id, só se for da assembleia (e do escritório dela). */
    public function buscarDaAssembleia(int $id, Assembleia $assembleia): ?OcorrenciaDaUnidade
    {
        return $this->findOneBy(['id' => $id, 'assembleia' => $assembleia, 'tenant' => $assembleia->getTenant()]);
    }

    /**
     * Guarda de posse com a linha TRAVADA (SELECT ... FOR UPDATE) e o estado relido do banco: duas decisões simultâneas da
     * mesma avaliação se serializam. Exige transação aberta.
     */
    public function buscarTravadaDaAssembleia(int $id, Assembleia $assembleia): ?OcorrenciaDaUnidade
    {
        /** @var OcorrenciaDaUnidade|null $ocorrencia */
        $ocorrencia = $this->createQueryBuilder('o')
            ->andWhere('o.id = :id AND o.assembleia = :assembleia AND o.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('assembleia', $assembleia)
            ->setParameter('tenant', $assembleia->getTenant())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $ocorrencia;
    }

    /** A avaliação pendente da unidade, se houver (no máximo uma, pelo índice único parcial). */
    public function pendenteDaUnidade(UnidadeDaAssembleia $unidade): ?OcorrenciaDaUnidade
    {
        return $this->findOneBy(['unidade' => $unidade, 'tenant' => $unidade->getTenant(), 'avaliacao' => AvaliacaoDaOcorrencia::Pendente]);
    }

    /**
     * As ocorrências da assembleia, as pendentes primeiro e depois a mais recente.
     *
     * @return list<OcorrenciaDaUnidade>
     */
    public function listarDaAssembleia(Assembleia $assembleia): array
    {
        /** @var list<OcorrenciaDaUnidade> $lista */
        $lista = $this->createQueryBuilder('o')
            ->addSelect('u')
            ->innerJoin('o.unidade', 'u')
            ->andWhere('o.assembleia = :assembleia AND o.tenant = :tenant')
            ->setParameter('assembleia', $assembleia)
            ->setParameter('tenant', $assembleia->getTenant())
            ->orderBy('o.registradaEm', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $lista;
    }

    /**
     * A última ocorrência de cada unidade da assembleia (a da cédula e da lista), por id da unidade.
     *
     * @return array<int, OcorrenciaDaUnidade>
     */
    public function ultimaPorUnidade(Assembleia $assembleia): array
    {
        $ultimas = [];
        foreach ($this->listarDaAssembleia($assembleia) as $ocorrencia) {
            $ultimas[(int) $ocorrencia->getUnidade()->getId()] ??= $ocorrencia;
        }

        return $ultimas;
    }

    /** A última ocorrência da unidade (a mais recente), para a cédula dela. */
    public function ultimaDaUnidade(UnidadeDaAssembleia $unidade): ?OcorrenciaDaUnidade
    {
        /** @var OcorrenciaDaUnidade|null $ocorrencia */
        $ocorrencia = $this->createQueryBuilder('o')
            ->andWhere('o.unidade = :unidade AND o.tenant = :tenant')
            ->setParameter('unidade', $unidade)
            ->setParameter('tenant', $unidade->getTenant())
            ->orderBy('o.registradaEm', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $ocorrencia;
    }

    /**
     * "N alteração(ões) por decisão do conselho": as ocorrências `tornada_apta` decididas DEPOIS de a lista travar.
     * Lista que ainda não travou (ou travou antes do hash existir) não tem contagem.
     */
    public function contarAlteracoesDepoisDaTrava(Assembleia $assembleia): int
    {
        $trava = $assembleia->getListaTravadaEm();
        if (null === $trava) {
            return 0;
        }

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.assembleia = :assembleia AND o.tenant = :tenant')
            ->andWhere('o.avaliacao = :tornada AND o.decididaEm > :trava')
            ->setParameter('assembleia', $assembleia)
            ->setParameter('tenant', $assembleia->getTenant())
            ->setParameter('tornada', AvaliacaoDaOcorrencia::TornadaApta)
            ->setParameter('trava', $trava)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Os números do Master, por assembleia (não por pauta): ocorrências, avaliações pendentes, inaptidões mantidas e
     * tornados aptos. Uma consulta para o lote; assembleia sem ocorrência não aparece no resultado. Com `$tenant` filtra
     * pelo escritório; sem ele (a leitura do Master, que cruza escritórios e só devolve números) conta pelos ids.
     *
     * @param list<int> $assembleiaIds
     *
     * @return array<int, array{ocorrencias: int, pendentes: int, mantidas: int, tornados_aptos: int}>
     */
    public function contagensPorAssembleia(array $assembleiaIds, ?Tenant $tenant = null): array
    {
        if ([] === $assembleiaIds) {
            return [];
        }
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT assembleia_id, COUNT(*) AS ocorrencias,
                    COUNT(*) FILTER (WHERE avaliacao = 'pendente') AS pendentes,
                    COUNT(*) FILTER (WHERE avaliacao = 'mantida') AS mantidas,
                    COUNT(*) FILTER (WHERE avaliacao = 'tornada_apta') AS tornados_aptos
               FROM voto.ocorrencia_da_unidade
              WHERE assembleia_id IN (:ids)" . (null === $tenant ? '' : ' AND tenant_id = :tenant') . '
              GROUP BY assembleia_id',
            null === $tenant ? ['ids' => $assembleiaIds] : ['ids' => $assembleiaIds, 'tenant' => $tenant->getId()],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $contagens = [];
        foreach ($linhas as $linha) {
            $contagens[(int) $linha['assembleia_id']] = [
                'ocorrencias' => (int) $linha['ocorrencias'],
                'pendentes' => (int) $linha['pendentes'],
                'mantidas' => (int) $linha['mantidas'],
                'tornados_aptos' => (int) $linha['tornados_aptos'],
            ];
        }

        return $contagens;
    }
}
