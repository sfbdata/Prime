<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaDocumentoFavorito;
use App\Pasta\Entity\PastaSecao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Favoritos (estrela) de arquivo e de subpasta na aba Documentos. Toda consulta é por escritório E
 * por usuário: favorito é preferência pessoal, e o de um colega não muda nada para mim.
 *
 * @extends ServiceEntityRepository<PastaDocumentoFavorito>
 */
class PastaDocumentoFavoritoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaDocumentoFavorito::class);
    }

    /**
     * Os favoritos do usuário DENTRO desta pasta, em UMA consulta, como conjuntos (`[id => true]`)
     * — o formato que o explorador consulta linha a linha sem varrer array.
     *
     * A pasta entra pelo alvo (documento ou seção), não por coluna própria: o favorito não guarda
     * `pasta_id`, e um documento movido entre seções continua favorito.
     *
     * @return array{documentos: array<int, true>, secoes: array<int, true>}
     */
    public function idsFavoritosDaPasta(User $usuario, Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<array{documento_id: int|string|null, secao_id: int|string|null}> $linhas */
        $linhas = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.documento) AS documento_id', 'IDENTITY(f.secao) AS secao_id')
            ->leftJoin('f.documento', 'd')
            ->leftJoin('f.secao', 's')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->andWhere('d.pasta = :pasta OR s.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getArrayResult();

        $documentos = [];
        $secoes     = [];
        foreach ($linhas as $linha) {
            if ($linha['documento_id'] !== null) {
                $documentos[(int) $linha['documento_id']] = true;
            }
            if ($linha['secao_id'] !== null) {
                $secoes[(int) $linha['secao_id']] = true;
            }
        }

        return ['documentos' => $documentos, 'secoes' => $secoes];
    }

    public function documentoEhFavorito(PastaDocumento $documento, User $usuario, Tenant $tenant): bool
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->andWhere('f.documento = :documento')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('documento', $documento)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Marca o alvo de forma IDEMPOTENTE: se a linha já existe (clique duplo, duas abas), não faz
     * nada e não dá erro.
     *
     * SQL direto com `ON CONFLICT DO NOTHING`, e não `persist()` + `flush()`, pelo mesmo motivo de
     * {@see PastaFavoritaRepository::inserirSeAusente()}: no caminho do ORM a corrida estoura
     * `UniqueConstraintViolationException` e FECHA o EntityManager. Sem alvo de conflito explícito
     * porque há dois UNIQUE (um por tipo de alvo) e qualquer um deles é o árbitro.
     */
    public function marcarSeAusente(Tenant $tenant, User $usuario, PastaDocumento|PastaSecao $alvo): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO pasta_documento_favorito (criado_em, tenant_id, user_id, documento_id, secao_id)
             VALUES (:criado_em, :tenant, :usuario, :documento, :secao)
             ON CONFLICT DO NOTHING',
            [
                'criado_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'tenant'    => $tenant->getId(),
                'usuario'   => $usuario->getId(),
                'documento' => $alvo instanceof PastaDocumento ? $alvo->getId() : null,
                'secao'     => $alvo instanceof PastaSecao ? $alvo->getId() : null,
            ],
        );
    }

    /** Desmarca o alvo; sem linha, não faz nada (idempotente). Escopado por tenant e usuário. */
    public function desmarcar(Tenant $tenant, User $usuario, PastaDocumento|PastaSecao $alvo): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(PastaDocumentoFavorito::class, 'f')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->andWhere($alvo instanceof PastaDocumento ? 'f.documento = :alvo' : 'f.secao = :alvo')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('alvo', $alvo)
            ->getQuery()
            ->execute();
    }
}
