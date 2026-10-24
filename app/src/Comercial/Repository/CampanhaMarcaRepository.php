<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\CampanhaMarca;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As marcas de cada Master nas campanhas do Relatório (spec `comercial-marcas.md`): estrela, arquivo e remoção, por SQL
 * na conexão DBAL, como o resto do Comercial. Uma gravação só por pedido: um `INSERT ... SELECT` das campanhas que
 * existem (id inexistente é ignorado) com `ON CONFLICT` na chave (campanha, Master), que muda só a coluna da ação.
 *
 * Plataforma, do Master: a tabela não tem `tenant_id`; o que separa as marcas é o `user_id` de quem marcou.
 *
 * @extends ServiceEntityRepository<CampanhaMarca>
 */
class CampanhaMarcaRepository extends ServiceEntityRepository
{
    public const ESTRELA = 'estrela';
    public const SEM_ESTRELA = 'sem_estrela';
    public const ARQUIVAR = 'arquivar';
    public const DESARQUIVAR = 'desarquivar';
    public const REMOVER = 'remover';
    public const ACOES = [self::ESTRELA, self::SEM_ESTRELA, self::ARQUIVAR, self::DESARQUIVAR, self::REMOVER];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampanhaMarca::class);
    }

    /**
     * Aplica a ação às campanhas dadas, para o Master dado. Devolve quantas campanhas foram marcadas.
     *
     * @param list<int> $ids
     */
    public function marcar(array $ids, int $masterId, string $acao, \DateTimeImmutable $agora): int
    {
        if ($ids === [] || !\in_array($acao, self::ACOES, true)) {
            return 0;
        }
        $coluna = self::coluna($acao);

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO comercial_campanha_marca (campanha_id, user_id, estrela, arquivada, removida_em, alterada_em)
             SELECT c.id, :master, :estrela, :arquivada, :removida, :agora FROM comercial_campanha c WHERE c.id IN (:ids)
             ON CONFLICT (campanha_id, user_id) DO UPDATE SET ' . $coluna . ' = EXCLUDED.' . $coluna . ', alterada_em = EXCLUDED.alterada_em',
            [
                'master' => $masterId, 'estrela' => $acao === self::ESTRELA, 'arquivada' => $acao === self::ARQUIVAR,
                'removida' => $acao === self::REMOVER ? $agora->format('Y-m-d H:i:s') : null,
                'agora' => $agora->format('Y-m-d H:i:s'), 'ids' => $ids,
            ],
            ['estrela' => ParameterType::BOOLEAN, 'arquivada' => ParameterType::BOOLEAN, 'ids' => ArrayParameterType::INTEGER],
        );
    }

    /** A coluna que a ação muda (de uma lista fechada: nada do pedido entra no SQL). */
    private static function coluna(string $acao): string
    {
        return match ($acao) {
            self::ESTRELA, self::SEM_ESTRELA => 'estrela',
            self::ARQUIVAR, self::DESARQUIVAR => 'arquivada',
            default => 'removida_em',
        };
    }
}
