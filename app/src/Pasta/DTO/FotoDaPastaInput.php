<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pedido de trocar (com `arquivo`) ou remover (sem) a foto do cabeçalho da pasta `pastaId` sem cliente cadastrado.
 * O id chega cru da rota: quem o confere contra o escritório é o UseCase.
 */
final class FotoDaPastaInput
{
    public function __construct(
        public readonly int $pastaId,
        public readonly User $usuario,
        public readonly Tenant $tenant,
        public readonly ?UploadedFile $arquivo = null,
    ) {
    }
}
