<?php

declare(strict_types=1);

namespace App\Shared\Contract;

/**
 * Marca uma entidade que, ao ser "excluída" pela tela, vai para a LIXEIRA em vez de sair do banco:
 * a linha fica, com `excluido_em`/`excluido_por` preenchidos (lápide), até a purga.
 *
 * O `LixeiraFilter` injeta `excluido_em IS NULL` em TODA leitura de entidade que implementa esta
 * interface — DQL, `find()`, coleções preguiçosas — para que o resto do sistema continue vendo
 * "excluído" como "não existe", sem reescrever cada consulta. Quem precisa enxergar a lixeira
 * (restaurar, listar, purgar) pede à `AcessoALixeira`, com escopo mínimo.
 *
 * A propriedade mapeada precisa se chamar `excluidoEm` (é por ela que o filtro acha a coluna).
 */
interface Descartavel
{
    public function getExcluidoEm(): ?\DateTimeImmutable;

    public function estaNaLixeira(): bool;
}
