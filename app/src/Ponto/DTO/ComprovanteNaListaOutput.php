<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/** Uma linha da aba Comprovantes (desenho 16 L196): data e hora, tipo, método, código e o id para o "Baixar". */
final readonly class ComprovanteNaListaOutput
{
    public function __construct(
        public int $id,
        public string $dataHora,
        public string $tipo,
        public string $metodo,
        public string $codigo,
    ) {
    }

    public static function dosDados(int $id, DadosDoComprovante $dados): self
    {
        return new self($id, $dados->dataHora->format('d/m/Y H:i:s'), $dados->nomeDoTipo(), $dados->nomeDoMetodo(), $dados->codigo);
    }
}
