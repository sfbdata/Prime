<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Uma linha do Relatório (desenho 05 VS 21.10.26, L304 e L714): o público com o total, o selo do estado, o nome e o
 * subtítulo ("modelo[ · frequência]"), a leitura (✓/✓✓ com o % e o tooltip) e a data (a agendada, ou a da
 * confirmação), em ISO local; a tela escreve "14:30" (hoje) ou "21 out" (outro dia), como o dc. `estrela`: a marca do
 * Master logado (F3e, spec `comercial-marcas.md`), o botão antes do público.
 */
final class LinhaDoRelatorio
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $sub,
        public readonly string $publico,
        public readonly int $total,
        public readonly string $estado,
        public readonly string $rotulo,
        public readonly string $data,
        public readonly MetricasDaCampanha $metricas,
        public readonly bool $estrela = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'id' => $this->id, 'nome' => $this->nome, 'sub' => $this->sub, 'publico' => $this->publico, 'total' => $this->total,
            'estado' => $this->estado, 'rotulo' => $this->rotulo, 'data' => $this->data, 'metricas' => $this->metricas->paraArray(),
            'estrela' => $this->estrela,
        ];
    }
}
