<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As buscas da equipe recebem a assembleia e filtram pelo escritório DELA, por escrito. A da cédula pública
 * ({@see findOneByTokenHash()}) roda sem escritório na sessão.
 *
 * @extends ServiceEntityRepository<UnidadeDaAssembleia>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class UnidadeDaAssembleiaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnidadeDaAssembleia::class);
    }

    public function salvar(UnidadeDaAssembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(UnidadeDaAssembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * A unidade do link pessoal, com a assembleia junto, pelo hash do token (TokenDaUnidade::hash()). Roda na página
     * pública, onde o filtro de tenant está desligado; por isso não depende dele: o hash é único no banco inteiro, e o
     * filtro é suspenso durante a consulta se estiver ligado (morador logado em outro escritório também abre o link).
     * Hash fora do formato (64 hex) devolve null sem consultar.
     */
    public function findOneByTokenHash(string $tokenHash): ?UnidadeDaAssembleia
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
            return null;
        }
        $filtros = $this->getEntityManager()->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        if ($estavaLigado) {
            $filtros->suspend('tenant');
        }
        try {
            return $this->buscarPeloHash($tokenHash);
        } finally {
            if ($estavaLigado) {
                $filtros->restore('tenant');
            }
        }
    }

    /** Guarda de posse: a unidade pelo id, só se for da assembleia (e do escritório dela). */
    public function buscarDaAssembleia(int $id, Assembleia $assembleia): ?UnidadeDaAssembleia
    {
        return $this->findOneBy(['id' => $id, 'assembleia' => $assembleia, 'tenant' => $assembleia->getTenant()]);
    }

    /** @return list<UnidadeDaAssembleia> por bloco e identificação */
    public function listarDaAssembleia(Assembleia $assembleia): array
    {
        /** @var list<UnidadeDaAssembleia> $unidades */
        $unidades = $this->daAssembleia($assembleia)->getQuery()->getResult();

        return $unidades;
    }

    /**
     * Próximo lote de "Enviar links por e-mail": unidade apta que tem e-mail e ainda não recebeu o link atual (a inapta
     * não vota e não recebe link).
     *
     * @return list<UnidadeDaAssembleia>
     */
    public function listarParaEnvioDoLink(Assembleia $assembleia, int $limite = 25): array
    {
        /** @var list<UnidadeDaAssembleia> $unidades */
        $unidades = $this->semLinkEnviado($this->daAssembleia($assembleia))
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $unidades;
    }

    /** O "faltam N" do envio por e-mail (só aptas). */
    public function contarParaEnvioDoLink(Assembleia $assembleia): int
    {
        return (int) $this->semLinkEnviado($this->daAssembleia($assembleia))
            ->select('COUNT(u.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Soma do peso apto e número de unidades aptas da assembleia (base do quórum e da apuração). Peso com 6 casas, em
     * string: a soma das frações das aptas quando o peso é a fração ideal; o número de aptas quando é uma unidade, um
     * voto.
     *
     * @return array{pesoApto: string, unidadesAptas: int}
     */
    public function totaisAptos(Assembleia $assembleia): array
    {
        $linha = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS unidades, COALESCE(SUM(fracao), 0) AS fracoes
               FROM voto.assembleia_unidade
              WHERE assembleia_id = :assembleia AND tenant_id = :tenant AND apta = TRUE',
            ['assembleia' => $assembleia->getId(), 'tenant' => $assembleia->getTenant()->getId()],
        );
        $unidades = (int) ($linha['unidades'] ?? 0);
        $peso = PesoDoVoto::Unidade === $assembleia->getPeso() ? (string) $unidades : (string) ($linha['fracoes'] ?? '0');

        return ['pesoApto' => bcadd($peso, '0', 6), 'unidadesAptas' => $unidades];
    }

    private function buscarPeloHash(string $tokenHash): ?UnidadeDaAssembleia
    {
        /** @var UnidadeDaAssembleia|null $unidade */
        $unidade = $this->createQueryBuilder('u')
            ->addSelect('a')
            ->innerJoin('u.assembleia', 'a')
            ->andWhere('u.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();

        return $unidade;
    }

    private function daAssembleia(Assembleia $assembleia): QueryBuilder
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.assembleia = :assembleia')
            ->andWhere('u.tenant = :tenant')
            ->setParameter('assembleia', $assembleia)
            ->setParameter('tenant', $assembleia->getTenant())
            ->orderBy('u.bloco', 'ASC')
            ->addOrderBy('u.identificacao', 'ASC')
            ->addOrderBy('u.id', 'ASC');
    }

    private function semLinkEnviado(QueryBuilder $qb): QueryBuilder
    {
        return $qb->andWhere('u.apta = true')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere("u.email <> ''")
            ->andWhere('u.linkEnviadoEm IS NULL');
    }
}
