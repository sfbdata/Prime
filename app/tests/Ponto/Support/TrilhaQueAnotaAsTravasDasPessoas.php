<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Support;

use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A trilha do Ponto que anota, a cada evento, quais das pessoas vigiadas já tinham a linhagem travada (4210,
 * {@see GravacaoDoAjusteDoPonto::CLASSE_TRAVA}) nesta conexão. A anotação é feita no começo de `travar`, a trava do
 * escritório (4203) que abre todo evento da trilha: prova que a ordem das travas não se inverte (E10).
 *
 * Sob o DAMA o teste inteiro roda numa transação, e a trava de transação pega uma vez fica segura até o fim do teste.
 * Por isso quem usa confere, ANTES da ação, que a pessoa ainda não está travada ({@see self::travadas}); sem isso a
 * anotação do evento não prova nada.
 */
final class TrilhaQueAnotaAsTravasDasPessoas extends TrilhaDoPontoRepository
{
    /** @var list<list<int>> a cada evento da trilha, os ids vigiados com a linhagem segura, em ordem crescente */
    public array $travadasPorEvento = [];

    /** @param list<int> $vigiadas os ids das pessoas a conferir */
    public function __construct(ManagerRegistry $registry, private readonly array $vigiadas)
    {
        parent::__construct($registry);
    }

    public function travar(int $tenantId): void
    {
        $this->travadasPorEvento[] = self::travadas($this->getEntityManager()->getConnection(), $this->vigiadas);
        parent::travar($tenantId);
    }

    /**
     * As pessoas, entre as informadas, com a linhagem (4210) travada por esta conexão agora.
     *
     * @param list<int> $pessoas
     *
     * @return list<int> em ordem crescente
     */
    public static function travadas(Connection $conexao, array $pessoas): array
    {
        $comTrava = array_map('intval', $conexao->fetchFirstColumn(
            "SELECT objid FROM pg_locks WHERE locktype = 'advisory' AND classid = ? AND objsubid = 2 AND pid = pg_backend_pid() AND granted",
            [GravacaoDoAjusteDoPonto::CLASSE_TRAVA],
        ));
        $travadas = array_values(array_intersect($pessoas, $comTrava));
        sort($travadas);

        return $travadas;
    }
}
