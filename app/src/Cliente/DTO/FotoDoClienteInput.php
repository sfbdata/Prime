<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pedido de trocar (com `arquivo`) ou remover (sem) a foto do cliente, vindo do cabeçalho da pasta `pastaId`.
 * Os ids chegam crus da rota: quem os confere contra o escritório é o UseCase.
 */
final class FotoDoClienteInput
{
    public function __construct(
        public readonly int $pastaId,
        public readonly int $clienteId,
        public readonly User $usuario,
        public readonly Tenant $tenant,
        public readonly ?UploadedFile $arquivo = null,
    ) {
    }
}
