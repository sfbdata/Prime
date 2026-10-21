<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

/** Um descadastro ATIVO na listagem ({@see DescadastroDeEmailInterface::listar()}). */
final readonly class ItemDeDescadastro
{
    public function __construct(
        public string $email,
        public string $assunto,
        public string $origem,
        public ?string $motivo,
        public \DateTimeImmutable $em,
    ) {
    }
}
