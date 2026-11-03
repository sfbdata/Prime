<?php

declare(strict_types=1);

namespace App\Blog\Repository;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Imagens dos artigos dos blogs. Sem filtro de tenant de propósito: a imagem pertence a um artigo, que é conteúdo
 * GLOBAL da plataforma, escrito só pelo Master (ver {@see ImagemDoArtigo}).
 *
 * @extends ServiceEntityRepository<ImagemDoArtigo>
 */
class ImagemDoArtigoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImagemDoArtigo::class);
    }

    public function salvar(ImagemDoArtigo $imagem, bool $flush = false): void
    {
        $this->getEntityManager()->persist($imagem);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ImagemDoArtigo $imagem, bool $flush = false): void
    {
        $this->getEntityManager()->remove($imagem);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function porId(int $id): ?ImagemDoArtigo
    {
        return $this->find($id);
    }

    /** @return list<ImagemDoArtigo> */
    public function doArtigo(ArtigoDoBlog $artigo): array
    {
        /** @var list<ImagemDoArtigo> */
        return $this->createQueryBuilder('i')
            ->andWhere('i.artigo = :artigo')
            ->setParameter('artigo', $artigo)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Extensão de cada imagem do artigo, por id: o mapa que o conversor usa para `./imagens/{id}.{ext}`.
     *
     * @return array<int, string>
     */
    public function extensoesDoArtigo(ArtigoDoBlog $artigo): array
    {
        $mapa = [];
        foreach ($this->doArtigo($artigo) as $imagem) {
            $mapa[(int) $imagem->getId()] = $imagem->extensao();
        }

        return $mapa;
    }
}
