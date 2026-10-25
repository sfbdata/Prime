<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As versões ajustadas dos documentos jurídicos enviadas pelo Master. PLATAFORMA, sem tenant.
 *
 * @extends ServiceEntityRepository<VersaoDeDocumentoJuridico>
 */
class VersaoDeDocumentoJuridicoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VersaoDeDocumentoJuridico::class);
    }

    /** @return array<string, list<VersaoDeDocumentoJuridico>> pela chave do documento, a mais recente primeiro */
    public function todasPorDocumento(): array
    {
        $porDocumento = [];
        foreach ($this->recentes() as $versao) {
            $porDocumento[$versao->getDocumento()->value][] = $versao;
        }

        return $porDocumento;
    }

    public function porId(int $id): ?VersaoDeDocumentoJuridico
    {
        return $this->find($id);
    }

    /** Marca a versão nova para persistir; quem confirma é a transação com arquivo novo do UseCase. */
    public function adicionar(VersaoDeDocumentoJuridico $versao): void
    {
        $this->getEntityManager()->persist($versao);
    }

    /** @return list<VersaoDeDocumentoJuridico> */
    private function recentes(): array
    {
        return $this->createQueryBuilder('v')
            ->orderBy('v.enviadaEm', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
