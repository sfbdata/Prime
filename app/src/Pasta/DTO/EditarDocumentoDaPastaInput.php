<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Os quatro campos do modal "Editar" da aba Documentos, crus como vieram do formulário (D3).
 *
 * A semântica de cada um é a do `editDocumento` que este fluxo substituiu, e mora no UseCase:
 * categoria desconhecida mantém a atual; descrição e número vazios viram NULL; `nomeBase` vazio
 * mantém o nome, e o preenchido recebe a extensão atual de volta (o usuário nunca a edita).
 */
final class EditarDocumentoDaPastaInput
{
    public function __construct(
        public readonly ?string $categoria,
        public readonly ?string $descricao,
        public readonly ?string $numero,
        public readonly ?string $nomeBase,
    ) {
    }
}
