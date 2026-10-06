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
    /** Formato de `favoritoEm` na tela (ISO sem fuso, como `criado_em`): ordena como texto. */
    public const FORMATO_FAVORITO_EM = 'Y-m-d\TH:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaDocumentoFavorito::class);
    }

    /**
     * Os favoritos do usuário DENTRO desta pasta, em UMA consulta, como mapas `[id => favoritoEm]`
     * (a hora em que foi marcado, em {@see self::FORMATO_FAVORITO_EM}) — o explorador consulta
     * linha a linha sem varrer array (`isset`) e ordena o topo pela hora, como o desenho (L4440).
     *
     * A pasta entra pelo alvo (documento ou seção), não por coluna própria: o favorito não guarda
     * `pasta_id`, e um documento movido entre seções continua favorito.
     *
     * @return array{documentos: array<int, string>, secoes: array<int, string>}
     */
    public function idsFavoritosDaPasta(User $usuario, Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<array{documento_id: int|string|null, secao_id: int|string|null, criado_em: mixed}> $linhas */
        $linhas = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.documento) AS documento_id', 'IDENTITY(f.secao) AS secao_id', 'f.criadoEm AS criado_em')
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
            $quando = self::formatarFavoritoEm($linha['criado_em']);
            if ($linha['documento_id'] !== null) {
                $documentos[(int) $linha['documento_id']] = $quando;
            }
            if ($linha['secao_id'] !== null) {
                $secoes[(int) $linha['secao_id']] = $quando;
            }
        }

        return ['documentos' => $documentos, 'secoes' => $secoes];
    }

    /**
     * Quando ESTE usuário marcou o alvo (NULL = não é favorito). Escopado por tenant e usuário —
     * é o que a edição em XHR e a resposta da estrela devolvem para a tela ordenar o topo.
     */
    public function marcadoEm(PastaDocumento|PastaSecao $alvo, User $usuario, Tenant $tenant): ?string
    {
        /** @var array{criado_em: mixed}|null $linha */
        $linha = $this->createQueryBuilder('f')
            ->select('f.criadoEm AS criado_em')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->andWhere($alvo instanceof PastaDocumento ? 'f.documento = :alvo' : 'f.secao = :alvo')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('alvo', $alvo)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $linha === null ? null : self::formatarFavoritoEm($linha['criado_em']);
    }

    /** A coluna chega como objeto de data (hidratada) ou texto (driver): a tela recebe sempre o mesmo formato. */
    private static function formatarFavoritoEm(mixed $valor): string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format(self::FORMATO_FAVORITO_EM);
        }

        return (new \DateTimeImmutable((string) $valor))->format(self::FORMATO_FAVORITO_EM);
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
