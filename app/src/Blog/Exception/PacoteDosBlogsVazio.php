<?php

declare(strict_types=1);

namespace App\Blog\Exception;

/**
 * Nenhum artigo publicado nos dois blogs: o pacote é recusado, porque o `rsync --delete` do builder tiraria todos os
 * artigos do ar (um banco vazio por engano não pode apagar os blogs). O pedido vira falhou com esta mensagem.
 */
final class PacoteDosBlogsVazio extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Nenhum artigo publicado: o pacote tiraria todos os artigos do ar.');
    }
}
