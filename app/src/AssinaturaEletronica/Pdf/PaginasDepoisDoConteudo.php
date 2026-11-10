<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Ponto de extensão: páginas que vêm depois do conteúdo (o manifesto do bloco 1b). Viaja dentro da
 * {@see FolhaParaMontar}, com os dados que precisar; o padrão é {@see SemPaginasDepoisDoConteudo}.
 *
 * Para cada página o montador pinta a moldura completa (fundo de segurança, mesmo na folha branca, e cabeçalho do
 * escritório), chama {@see desenhar()} e depois pinta o rodapé completo (QR, código, hash e selo) por cima. As páginas
 * entram na numeração "PÁGINA n DE total" de todas as páginas do documento.
 */
interface PaginasDepoisDoConteudo
{
    public function quantas(): int;

    /**
     * Desenha o miolo da página $indice (de 0 a quantas() - 1). Área livre: x de 92 a 882 e y de 170 a 1220
     * ({@see MolduraDaFolha::X}, {@see MolduraDaFolha::L}, {@see MolduraDaFolha::TOPO}, {@see MolduraDaFolha::BASE}).
     */
    public function desenhar(int $indice, Tela $tela, RecursosDaFolha $recursos): void;
}
