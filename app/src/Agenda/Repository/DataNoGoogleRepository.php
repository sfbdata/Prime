<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\DataNoGoogle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Datas do sistema na agenda do escritório no Google de cada pessoa. Roda no worker, onde o filtro de escritório
 * não age: toda consulta vai pela conexão (que já é de um escritório).
 *
 * @extends ServiceEntityRepository<DataNoGoogle>
 */
class DataNoGoogleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataNoGoogle::class);
    }

    /** @return list<DataNoGoogle> */
    public function daConexao(ConexaoGoogleAgenda $conexao): array
    {
        return $this->findBy(['conexao' => $conexao], ['id' => 'ASC']);
    }

    public function daConexaoPeloGoogle(ConexaoGoogleAgenda $conexao, string $googleId): ?DataNoGoogle
    {
        return $this->findOneBy(['conexao' => $conexao, 'googleId' => $googleId]);
    }

    /** Outra conta ou agenda nova: as linhas antigas não valem (as datas vão de novo). */
    public function esquecerDaConexao(ConexaoGoogleAgenda $conexao): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM agenda_google_data WHERE conexao_id = :c',
            ['c' => $conexao->getId()],
        );
    }

    public function salvar(DataNoGoogle $linha): void
    {
        $this->getEntityManager()->persist($linha);
    }

    public function remover(DataNoGoogle $linha): void
    {
        $this->getEntityManager()->remove($linha);
    }
}
