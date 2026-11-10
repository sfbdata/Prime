<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/** Nenhuma página depois do conteúdo (o padrão da {@see FolhaParaMontar}). */
final class SemPaginasDepoisDoConteudo implements PaginasDepoisDoConteudo
{
    public function quantas(): int
    {
        return 0;
    }

    public function desenhar(int $indice, Tela $tela, RecursosDaFolha $recursos): void
    {
    }
}
