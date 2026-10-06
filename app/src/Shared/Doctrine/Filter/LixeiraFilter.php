<?php

declare(strict_types=1);

namespace App\Shared\Doctrine\Filter;

use App\Shared\Contract\Descartavel;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Filtro Doctrine que injeta `excluido_em IS NULL` em TODA leitura de entidade {@see Descartavel}:
 * DQL (inclusive a entidade joinada), `find()`, contagem e carga das coleções preguiçosas
 * (`pasta.documentos`, `secao.documentos`, `secao.filhas`). É o que faz o item da lixeira sumir de
 * todas as telas e serviços de uma vez — explorador, sugestor, conferência do checklist, duplicados,
 * `view`/`download` (404 pelo resolver de argumento) — sem reescrever cada consulta.
 *
 * Ligado POR PADRÃO (`doctrine.yaml`, `enabled: true`): vale no request, no console e nos testes.
 * Não tem parâmetro. Quem precisa enxergar a lixeira (restaurar, listar, purgar) desliga pelo
 * {@see AcessoALixeira}, por escopo mínimo, nunca pelo `getFilters()` solto.
 *
 * O que o filtro NÃO faz, e que é obrigação de quem o usa:
 *  - não esconde entidade que JÁ está no identity map — uma consulta Doctrine não relê o que está
 *    carregado; quem marcou a lápide no mesmo request continua com o objeto na mão;
 *  - não passa por SQL cru (DBAL): quem lê `pasta_documento`/`pasta_secao` por `fetch*` escreve o
 *    `excluido_em IS NULL` à mão (o reconciliador do Drive faz isso).
 */
final class LixeiraFilter extends SQLFilter
{
    public const NOME = 'lixeira';

    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        $refl = $targetEntity->reflClass;
        if ($refl === null || !$refl->implementsInterface(Descartavel::class)) {
            return '';
        }

        return sprintf('%s.%s IS NULL', $targetTableAlias, $targetEntity->getColumnName('excluidoEm'));
    }
}
