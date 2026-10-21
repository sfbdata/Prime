<?php

declare(strict_types=1);

namespace App\Termo\Repository;

use App\Entity\Auth\User;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Enum\OrigemDoAceite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AceiteTermo>
 */
class AceiteTermoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AceiteTermo::class);
    }

    public function salvar(AceiteTermo $aceite, bool $flush = false): void
    {
        $this->getEntityManager()->persist($aceite);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Verifica se o usuário já aceitou a versão informada.
     *
     * Exceção consciente à regra de filtro por tenant: o aceite é da plataforma,
     * não de um escritório — a busca é por (user, versao), de qualquer origem menos o
     * cadastro não ratificado (D2b da spec cadastro-senha-no-link).
     */
    public function existeAceiteVigente(User $user, string $versao): bool
    {
        $resultado = $this->createQueryBuilder('a')
            ->select('1')
            ->where('a.user = :user')
            ->andWhere('a.versao = :versao')
            ->andWhere('a.origem <> :naoRatificado')
            ->setParameter('user', $user)
            ->setParameter('versao', $versao)
            ->setParameter('naoRatificado', OrigemDoAceite::CadastroNaoRatificado)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $resultado !== null;
    }

    /**
     * D2b: o aceite do cadastro deixa de valer (vira cadastro_nao_ratificado), sem apagar a linha. Roda na conexão
     * corrente, dentro da transação de quem chama.
     *
     * @return int quantas linhas mudaram
     */
    public function desratificarCadastro(User $user): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE aceite_termo SET origem = :nova WHERE user_id = :id AND origem = :velha',
            ['id' => $user->getId(), 'nova' => OrigemDoAceite::CadastroNaoRatificado->value, 'velha' => OrigemDoAceite::Cadastro->value],
        );
    }
}
