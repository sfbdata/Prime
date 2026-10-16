<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Enum\Perfil;

/**
 * Quem fez algo numa demanda, como ficou registrado: o usuário, o nome naquele momento e o perfil.
 * O perfil é gravado junto porque decide o que o leitor externo vê ("Central BlueJus" no lugar
 * do nome de quem atende) mesmo depois que o usuário muda de papel.
 */
final readonly class Autor
{
    public function __construct(
        public ?User $usuario,
        public string $nome,
        public Perfil $perfil,
    ) {
    }

    public static function de(User $usuario, Perfil $perfil): self
    {
        $nome = trim((string) $usuario->getFullName());

        return new self($usuario, $nome !== '' ? $nome : (string) $usuario->getEmail(), $perfil);
    }
}
