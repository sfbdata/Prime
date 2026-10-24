<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * Uma linha do banco que aponta para um arquivo. `chave` nula: o valor guardado não vira chave.
 * `categoriaDaFonte` é a categoria da coluna de onde a linha veio: com ela, até a referência sem chave
 * válida entra na contagem por categoria e no filtro das categorias ligadas.
 */
final readonly class ReferenciaDeArquivo
{
    public function __construct(
        public string $fonte,
        public int $idDaLinha,
        public ?ChaveDeArquivo $chave,
        public ?CategoriaDeArquivo $categoriaDaFonte = null,
    ) {
    }

    public function categoria(): ?CategoriaDeArquivo
    {
        return $this->chave->categoria ?? $this->categoriaDaFonte;
    }
}
