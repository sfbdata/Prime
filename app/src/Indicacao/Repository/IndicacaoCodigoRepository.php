<?php

declare(strict_types=1);

namespace App\Indicacao\Repository;

use App\Indicacao\Entity\IndicacaoCodigo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os códigos do link curto. A criação é por SQL com `ON CONFLICT DO NOTHING`: duas aberturas simultâneas da janela
 * pela mesma pessoa não geram erro (a segunda lê o código da primeira), e um código sorteado repetido só devolve
 * nulo para quem chama sortear outro.
 *
 * @extends ServiceEntityRepository<IndicacaoCodigo>
 */
class IndicacaoCodigoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicacaoCodigo::class);
    }

    public function codigoDoUsuario(int $userId): ?string
    {
        $codigo = $this->getEntityManager()->getConnection()->fetchOne('SELECT codigo FROM indicacao_codigo WHERE user_id = ?', [$userId]);

        return $codigo === false ? null : (string) $codigo;
    }

    /** Grava o candidato se a pessoa ainda não tem código; devolve o código dela, ou nulo se o candidato já era de outra. */
    public function gravarSeNaoTiver(int $userId, string $candidato): ?string
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO indicacao_codigo (user_id, codigo) VALUES (?, ?) ON CONFLICT DO NOTHING',
            [$userId, $candidato],
        );

        return $this->codigoDoUsuario($userId);
    }

    public function existe(string $codigo): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM indicacao_codigo WHERE codigo = ?)',
            [$codigo],
        );
    }
}
