<?php

declare(strict_types=1);

namespace App\Blog\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Montar o pacote do pedido de geração dos blogs `publicacaoId` (spec painel-artigos-blogs, seção 6.1). Vai para o
 * worker: o pacote lê o banco e copia as imagens do armazenamento, e é o worker que tem o volume dos pedidos.
 */
#[AsMessage('async')]
final readonly class MontarPacoteDosBlogs
{
    public function __construct(
        public int $publicacaoId,
    ) {
    }
}
