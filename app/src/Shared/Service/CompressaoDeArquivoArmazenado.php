<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use Psr\Log\LoggerInterface;

/**
 * Comprime um arquivo JÁ PERSISTIDO, pela chave (D31, E2.6A): materializar → comprimir → regravar →
 * medir. É o único lugar que junta materializador e compressor — os consumidores (uploads de
 * Cliente, Pasta, Peticionar e Cobrança) chamam só isto.
 *
 * ## O que ele garante
 *
 *  - **o original nunca é destruído** (INV-7, D26): o compressor trabalha numa cópia gravável, fora do
 *    volume; a volta para o storage é um `gravar()` na mesma chave, atômico no disco, e só acontece
 *    quando o compressor validou o resultado (D27). Falha em qualquer etapa — materializar,
 *    comprimir, regravar — vira log e deixa o arquivo como estava;
 *  - **o tamanho devolvido é o medido pelo storage** depois da última gravação válida (D30): o do
 *    `gravar()` quando a versão comprimida foi publicada, o de `metadados()` quando não foi. O número
 *    que o compressor relata não é usado;
 *  - **se nem medir for possível, o erro sobe** (D26): um tamanho desconhecido não pode virar dado
 *    no banco. Isso inclui a regravação que falhou DEPOIS de publicar ("gravou mas não conseguiu
 *    medir") — medir de novo decide, e se não der, lança;
 *  - **a cópia gravável é liberada em todos os caminhos** (D9).
 *
 * ## O que ele pressupõe
 *
 * Um único escritor na chave enquanto isto roda. Ele copia, comprime e regrava sem trava: uma
 * escrita concorrente na MESMA chave seria sobrescrita pela versão comprimida do conteúdo antigo.
 * Vale para os consumidores de hoje, que comprimem logo depois de cunhar a chave — nome novo que
 * mais ninguém conhece. Um dia comprimir arquivo já visível exige repensar isto.
 */
final class CompressaoDeArquivoArmazenado
{
    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly MaterializadorDeArquivo $materializador,
        private readonly CompressorArquivoInterface $compressor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return ResultadoCompressao com os tamanhos MEDIDOS: `tamanhoFinal` é o do arquivo que ficou
     *
     * @throws ArquivoNaoEncontrado quando não há arquivo na chave
     * @throws FalhaDeArmazenamento quando não dá para medir o arquivo com segurança
     */
    public function comprimir(ChaveDeArquivo $chave, string $mimeType): ResultadoCompressao
    {
        $tamanhoOriginal = $this->medir($chave);

        try {
            $copia = $this->materializador->copiaGravavel($chave);
        } catch (FalhaNoTemporario $e) {
            // SÓ o lado do temporário é engolido (D26): o arquivo do cliente está intacto e a
            // compressão é opcional. Não ler o persistido é pane e sobe — se subisse como aviso, o
            // upload terminaria "com sucesso" e o registro apontaria para arquivo ilegível.
            $this->avisar('Não foi possível preparar a cópia para comprimir; mantendo o original.', $chave, $e);

            return ResultadoCompressao::naoComprimido($this->medir($chave));
        }

        try {
            try {
                $resultado = $this->compressor->comprimir($copia->caminho(), $mimeType);
            } catch (\Throwable $e) {
                $this->avisar('O compressor falhou; mantendo o original.', $chave, $e);

                return ResultadoCompressao::naoComprimido($this->medir($chave));
            }

            if (!$resultado->comprimido) {
                return ResultadoCompressao::naoComprimido($this->medir($chave), $resultado->eraAssinado);
            }

            try {
                $armazenado = $this->armazenamento->gravar(
                    $chave,
                    FonteDeConteudo::deArquivoLocal($copia->caminho(), consumirOrigem: true),
                );
            } catch (FalhaDeArmazenamento $e) {
                $this->avisar('Não foi possível regravar a versão comprimida; mantendo o que está no storage.', $chave, $e);

                // Pode ter publicado ANTES de falhar (a medição posterior do backend é que quebrou).
                // Quem decide é o tamanho: mudou, então o que está lá já não é o original, e o
                // chamador precisa saber — é dele o aviso de "PDF assinado foi comprimido".
                $tamanhoAgora = $this->medir($chave);

                return new ResultadoCompressao(
                    $tamanhoOriginal,
                    $tamanhoAgora,
                    $tamanhoAgora !== $tamanhoOriginal,
                    $resultado->eraAssinado,
                );
            }

            return new ResultadoCompressao($tamanhoOriginal, $armazenado->tamanhoBytes, true, $resultado->eraAssinado);
        } finally {
            $copia->liberar();
        }
    }

    /**
     * @throws ArquivoNaoEncontrado
     * @throws FalhaDeArmazenamento
     */
    private function medir(ChaveDeArquivo $chave): int
    {
        $metadados = $this->armazenamento->metadados($chave);
        if ($metadados === null) {
            throw ArquivoNaoEncontrado::para($chave);
        }

        return $metadados->tamanhoBytes;
    }

    private function avisar(string $mensagem, ChaveDeArquivo $chave, \Throwable $e): void
    {
        $this->logger->warning($mensagem, [
            'chave' => $chave->comoTexto(),
            'erro'  => $e->getMessage(),
        ]);
    }
}
