<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Preferências de UM usuário: toda consulta recebe o usuário.
 *
 * @extends ServiceEntityRepository<PreferenciaDoUsuario>
 */
class PreferenciaDoUsuarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreferenciaDoUsuario::class);
    }

    public function salvar(PreferenciaDoUsuario $preferencia, bool $flush = false): void
    {
        $this->getEntityManager()->persist($preferencia);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function doUsuario(User $usuario, string $chave): ?PreferenciaDoUsuario
    {
        return $this->findOneBy(['usuario' => $usuario, 'chave' => $chave]);
    }

    public function obter(User $usuario, string $chave): PreferenciaDoUsuario
    {
        return $this->doUsuario($usuario, $chave) ?? new PreferenciaDoUsuario($usuario, $chave);
    }

    /**
     * Os avisos de vaga nova LIGADOS, de todos (o envio procura quem quer receber). O `ligado` é lido no
     * JSON pelo SQL; quem chama confere o escritório guardado em cada um.
     *
     * @return list<PreferenciaDoUsuario>
     */
    public function alertasDeVagasLigados(): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT id FROM trabalho_externo_preferencia WHERE chave = :chave AND (valor::jsonb ->> 'ligado') = 'true' ORDER BY id",
            ['chave' => PreferenciaDoUsuario::ALERTA_DE_VAGAS],
        );
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->addSelect('u')
            ->join('p.usuario', 'u')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', array_map('intval', $ids))
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
