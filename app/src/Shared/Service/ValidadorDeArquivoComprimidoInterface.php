<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Decide se a versão comprimida de um arquivo pode substituir o original (D27).
 *
 * "Ficou menor" não prova nada: um Ghostscript interrompido deixa um PDF menor, com código de saída
 * zero e sem página nenhuma. As duas perguntas daqui nunca lançam — na dúvida, a resposta é não, e o
 * original fica (INV-7).
 */
interface ValidadorDeArquivoComprimidoInterface
{
    /**
     * PDF que começa como PDF, termina como PDF e que o Ghostscript relê sem erro, com as páginas
     * esperadas.
     *
     * @param float|null $timeoutSegundos o que sobrou do orçamento de quem chama; null usa o próprio
     */
    public function pdfValido(string $caminho, int $paginasEsperadas, ?float $timeoutSegundos = null): bool;

    /** Imagem inteira, do tipo esperado, que o GD decodifica com a largura e a altura do original. */
    public function imagemValida(string $caminho, string $mimeType, int $largura, int $altura): bool;
}
