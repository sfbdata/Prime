<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Lê uma tabela do escritório em páginas por id (paginação por chave, sem OFFSET) e solta o EntityManager entre uma
 * página e a próxima: a memória do worker não cresce com o tamanho da carteira. Quem usa tem a propriedade `$em` (EntityManagerInterface).
 *
 * O escritório entra por id (`IDENTITY(e.tenant) = :tenantId`), escrito na mão: nada depende do `TenantFilter`, e o
 * `Tenant` não precisa continuar gerenciado depois do `clear()`. Quem consome deve transformar a página em dados
 * simples (arrays e strings) antes de pedir a próxima: as entidades da página anterior saem do EntityManager.
 */
trait PercorreEmPaginasTrait
{
    /**
     * @param class-string                 $classe
     * @param (callable(QueryBuilder): mixed)|null $ajuste junta e seleciona o que mais a página precisa (alias da raiz: `e`)
     *
     * @return \Generator<int, list<object>>
     */
    private function paginas(string $classe, int $tenantId, int $tamanho, ?callable $ajuste = null): \Generator
    {
        $ultimo = 0;
        while (true) {
            $qb = $this->em->createQueryBuilder()
                ->select('e')->from($classe, 'e')
                ->andWhere('IDENTITY(e.tenant) = :tenantId')->andWhere('e.id > :ultimo')
                ->setParameter('tenantId', $tenantId)->setParameter('ultimo', $ultimo)
                ->orderBy('e.id')->setMaxResults(max(1, $tamanho));
            if ($ajuste !== null) {
                $ajuste($qb);
            }
            /** @var list<object> $pagina */
            $pagina = array_values($qb->getQuery()->getResult());
            if ($pagina === []) {
                return;
            }

            yield $pagina;

            $ultimo = (int) $pagina[array_key_last($pagina)]->getId();
            $this->em->clear();
            if (count($pagina) < max(1, $tamanho)) {
                return;
            }
        }
    }
}
