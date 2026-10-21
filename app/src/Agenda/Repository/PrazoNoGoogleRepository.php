<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\PrazoNoGoogle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Prazos das pastas na agenda do escritório no Google de cada pessoa. Roda no worker, onde o filtro de escritório
 * não age: toda consulta vai pela conexão (que já é de um escritório).
 *
 * @extends ServiceEntityRepository<PrazoNoGoogle>
 */
class PrazoNoGoogleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrazoNoGoogle::class);
    }

    /** @return list<PrazoNoGoogle> */
    public function daConexao(ConexaoGoogleAgenda $conexao): array
    {
        return $this->findBy(['conexao' => $conexao], ['id' => 'ASC']);
    }

    public function daConexaoPeloGoogle(ConexaoGoogleAgenda $conexao, string $googleId): ?PrazoNoGoogle
    {
        return $this->findOneBy(['conexao' => $conexao, 'googleId' => $googleId]);
    }

    /** Outra conta ou agenda nova: as linhas antigas não valem (os prazos vão de novo). */
    public function esquecerDaConexao(ConexaoGoogleAgenda $conexao): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM agenda_google_prazo WHERE conexao_id = :c',
            ['c' => $conexao->getId()],
        );
    }

    public function salvar(PrazoNoGoogle $linha): void
    {
        $this->getEntityManager()->persist($linha);
    }

    public function remover(PrazoNoGoogle $linha): void
    {
        $this->getEntityManager()->remove($linha);
    }
}
