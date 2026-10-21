<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

/** Uma linha de "Encontrados no menu Documentos" (desenho 02, l.773 a 780): documento da pasta ainda não vinculado. */
final class DocumentoEncontradoNaPasta
{
    public function __construct(
        public readonly int $id,
        public readonly string $arquivo,
        public readonly string $categoria,
        public readonly string $categoriaRotulo,
        public readonly string $pasta,
        public readonly string $data,
        public readonly bool $podeVincular,
    ) {
    }

    /** @return array{id: int, arquivo: string, categoria: string, categoriaRotulo: string, pasta: string, data: string, podeVincular: bool} */
    public function paraResposta(): array
    {
        return [
            'id'              => $this->id,
            'arquivo'         => $this->arquivo,
            'categoria'       => $this->categoria,
            'categoriaRotulo' => $this->categoriaRotulo,
            'pasta'           => $this->pasta,
            'data'            => $this->data,
            'podeVincular'    => $this->podeVincular,
        ];
    }
}
