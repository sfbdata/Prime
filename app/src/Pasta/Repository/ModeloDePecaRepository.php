<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\FiltroDosModelosInput;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\OrdemDosModelos;
use App\Pasta\Enum\TipoDePeca;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModeloDePeca>
 */
class ModeloDePecaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeloDePeca::class);
    }

    /**
     * Guarda de posse: modelo de outro escritório volta null, e o controller responde 404.
     * Não confie só no TenantFilter global: ele não está ligado em CLI nem em teste.
     */
    public function buscarDoTenant(int $id, Tenant $tenant): ?ModeloDePeca
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id = :id')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As linhas da lista (sem o corpo, que só vem ao abrir o modelo), já filtradas e ordenadas.
     *
     * Sem paginação de propósito: o desenho não pagina e o banco de modelos é curado à mão pela
     * equipe; um teto silencioso esconderia modelo salvo. A busca olha título, tipo, matéria e
     * corpo (o desenho 02 faz o mesmo), sem ignorar acento.
     *
     * @return list<array{id: int, titulo: string, materia: MateriaDeModelo, tipo: TipoDePeca, usos: int, atualizadoEm: \DateTimeImmutable}>
     */
    public function listar(Tenant $tenant, FiltroDosModelosInput $filtro, ?User $usuarioFavoritos): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.id', 'm.titulo', 'm.materia', 'm.tipo', 'm.usos', 'm.atualizadoEm')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        if ($filtro->materia !== null) {
            $qb->andWhere('m.materia = :materia')->setParameter('materia', $filtro->materia);
        }
        if ($filtro->tipo !== null) {
            $qb->andWhere('m.tipo = :tipo')->setParameter('tipo', $filtro->tipo);
        }
        if ($filtro->soFavoritos && $usuarioFavoritos !== null) {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Pasta\Entity\ModeloDePecaFavorito f WHERE f.modelo = m AND f.usuario = :usuario)')
                ->setParameter('usuario', $usuarioFavoritos);
        }
        if ($filtro->busca !== '') {
            $this->aplicarBusca($qb, $filtro->busca);
        }

        $this->aplicarOrdem($qb, $filtro->ordem);

        /** @var list<array{id: int, titulo: string, materia: MateriaDeModelo, tipo: TipoDePeca, usos: int, atualizadoEm: \DateTimeImmutable}> $linhas */
        $linhas = $qb->getQuery()->getResult();

        return $linhas;
    }

    /**
     * Quantos modelos há por matéria, ignorando busca e tipo (contador dos chips da coluna esquerda).
     *
     * @return array<string, int> valor da matéria => quantidade
     */
    public function contarPorMateria(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('m')
            ->select('m.materia AS materia', 'COUNT(m.id) AS total')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('m.materia')
            ->getQuery()
            ->getArrayResult();

        $contagem = [];
        foreach ($linhas as $linha) {
            $materia = $linha['materia'];
            $chave   = $materia instanceof MateriaDeModelo ? $materia->value : (string) $materia;

            $contagem[$chave] = (int) $linha['total'];
        }

        return $contagem;
    }

    public function salvar(ModeloDePeca $modelo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($modelo);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ModeloDePeca $modelo, bool $flush = false): void
    {
        $this->getEntityManager()->remove($modelo);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    private function aplicarBusca(QueryBuilder $qb, string $busca): void
    {
        $termo = '%' . addcslashes(mb_strtolower($busca), '\\%_') . '%';
        $ou    = [
            'LOWER(m.titulo) LIKE :busca',
            'LOWER(m.corpo) LIKE :busca',
        ];

        $tipos = array_filter(TipoDePeca::cases(), static fn (TipoDePeca $t): bool => str_contains(mb_strtolower($t->rotulo()), mb_strtolower($busca)));
        if ($tipos !== []) {
            $ou[] = 'm.tipo IN (:tiposBuscados)';
            $qb->setParameter('tiposBuscados', array_values($tipos));
        }
        $materias = array_filter(MateriaDeModelo::cases(), static fn (MateriaDeModelo $x): bool => str_contains(mb_strtolower($x->rotulo()), mb_strtolower($busca)));
        if ($materias !== []) {
            $ou[] = 'm.materia IN (:materiasBuscadas)';
            $qb->setParameter('materiasBuscadas', array_values($materias));
        }

        $qb->andWhere('(' . implode(' OR ', $ou) . ')')->setParameter('busca', $termo);
    }

    /** Desempate pelo mais recente: no M1 todo `usos` é 0, e o desenho põe o novo no topo. */
    private function aplicarOrdem(QueryBuilder $qb, OrdemDosModelos $ordem): void
    {
        match ($ordem) {
            OrdemDosModelos::MaisUsados   => $qb->orderBy('m.usos', 'DESC')->addOrderBy('m.atualizadoEm', 'DESC')->addOrderBy('m.id', 'DESC'),
            OrdemDosModelos::MaisRecentes => $qb->orderBy('m.atualizadoEm', 'DESC')->addOrderBy('m.id', 'DESC'),
            OrdemDosModelos::AaZ          => $qb->orderBy('m.titulo', 'ASC')->addOrderBy('m.id', 'ASC'),
        };
    }
}
