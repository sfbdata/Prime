<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\DTO\ConteudoDaFolha;

/**
 * Quem desenha a página da folha do mês em JPEG ({@see ImagemDaFolha}). A interface existe para os testes da rota do
 * PDF trocarem o desenho por um espião que guarda o conteúdo desenhado: a página é imagem, sem texto para ler de volta.
 */
interface DesenhoDaFolha
{
    /**
     * @param ?string $enderecoDoQr     endereço absoluto da validação do código da folha; null na folha sem código
     * @param string  $enderecoDaPagina a página de validação, sem o protocolo, para o texto "Confira em ..."
     *
     * @return string o JPEG da página
     */
    public function gerar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina): string;
}
