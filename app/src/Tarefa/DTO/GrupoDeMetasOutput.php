<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\Tarefa;

/**
 * Um bloco da lista de "Minhas Metas": "Atrasadas", "Vencem em 7 dias", "Concluídas"…
 *
 * O agrupamento vive aqui, e não no Twig, porque é regra de negócio: qual meta é urgente e
 * quem já entregou o que estava com ele. Enquanto morava em `|filter` dentro do template não
 * havia como testá-lo, e a tela era o único lugar onde a regra existia.
 */
final class GrupoDeMetasOutput
{
    /**
     * @param string   $rotulo chave do catálogo (`metas.grupos.*`); o Twig aplica `|trans`
     * @param Tarefa[] $metas
     * @param string   $tom    papel visual da linha: danger, warn, cinza, accent ou ok (a cor do grupo
     *                         vem da classe `mm-grupo-<chave>` no CSS)
     */
    public function __construct(
        public readonly string $chave,
        public readonly string $rotulo,
        public readonly string $tom,
        public readonly array $metas,
    ) {
    }

    public function total(): int
    {
        return count($this->metas);
    }

    public function estaVazio(): bool
    {
        return $this->metas === [];
    }
}
