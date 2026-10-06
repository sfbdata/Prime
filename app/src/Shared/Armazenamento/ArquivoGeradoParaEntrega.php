<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * Um arquivo GERADO pela aplicação (o .zip da aba Documentos) que vai para o navegador e some depois
 * de entregue — o terceiro tipo de caminho local, ao lado de {@see ArquivoEmprestado} e
 * {@see ArquivoTemporarioPossuido}.
 *
 * ## Por que nem emprestado, nem possuído
 *
 * O emprestado nunca é apagado: é o arquivo de produção (INV-9). O possuído some no `finally` de
 * quem o criou — e a entrega HTTP só lê o arquivo DEPOIS de o controller retornar
 * (`sendContent()`), quando esse `finally` já passou: a resposta encontraria o arquivo apagado.
 * Este tipo existe para o intervalo entre os dois: o arquivo nasce numa
 * {@see AreaTemporariaPrivada} (que se apaga inteira ao fim da operação, com qualquer parcial), é
 * RETIRADO dela ({@see retirarDa()}) para o diretório privado do processo, e a partir daí quem o
 * entrega é quem o descarta — a entrega HTTP, depois de enviar (`deleteFileAfterSend`); ou
 * {@see descartar()}, se a entrega não acontecer.
 *
 * ## Só pode nascer da área
 *
 * Não há construtor público que aceite um caminho qualquer: a única porta exige a área e prova que
 * o arquivo é um arquivo regular DENTRO dela. É o que impede construir um "gerado" apontando para
 * um arquivo persistido e entregá-lo com descarte depois do envio.
 *
 * Não tem destrutor que apague, de propósito: o ciclo de vida é o da entrega, não o do objeto.
 */
final class ArquivoGeradoParaEntrega
{
    private function __construct(
        private readonly string $caminho,
    ) {
    }

    /**
     * Move o arquivo de dentro da área para o diretório privado do processo
     * (`jusprime-<finalidade>-<uid>`), com nome aleatório e a mesma extensão. Depois disto a área
     * pode ser liberada sem levá-lo junto.
     *
     * @param string $finalidade nome simples, o mesmo critério de {@see DiretorioTemporarioPrivado::doProcesso()}
     *
     * @throws FalhaNoTemporario se o caminho não está na área, não é arquivo regular, ou não pôde ser movido
     */
    public static function retirarDa(AreaTemporariaPrivada $area, string $caminhoNaArea, string $finalidade): self
    {
        if (\dirname($caminhoNaArea) !== $area->caminho() || is_link($caminhoNaArea) || !is_file($caminhoNaArea)) {
            throw new FalhaNoTemporario(sprintf(
                'O caminho %s não é um arquivo regular dentro da área temporária.',
                $caminhoNaArea,
            ));
        }

        $extensao = strtolower(pathinfo($caminhoNaArea, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,16}$/', $extensao) !== 1) {
            $extensao = 'bin';
        }

        $destino = DiretorioTemporarioPrivado::doProcesso($finalidade)->caminho()
            . '/' . bin2hex(random_bytes(8)) . '.' . $extensao;

        if (file_exists($destino) || !@rename($caminhoNaArea, $destino)) {
            throw new FalhaNoTemporario(sprintf(
                'Não foi possível retirar %s da área temporária para %s.',
                $caminhoNaArea,
                $destino,
            ));
        }

        @chmod($destino, 0o600);

        return new self($destino);
    }

    /** Caminho local do arquivo gerado. Some quando a entrega terminar ou em {@see descartar()}. */
    public function caminho(): string
    {
        return $this->caminho;
    }

    /** Idempotente. Para o caminho em que a entrega NÃO acontece (falha depois de gerar). */
    public function descartar(): void
    {
        if (is_file($this->caminho)) {
            @unlink($this->caminho);
        }
    }

    /** Sobra com mais de 1 h é de processo que morreu no meio ou de entrega que não aconteceu. */
    public const IDADE_DE_SOBRA_SEGUNDOS = 3600;

    /**
     * Limpeza OPORTUNISTA do diretório privado `jusprime-<finalidade>-<uid>`: remove o que ESTE
     * mecanismo criou e ficou para trás — áreas (`<16 hex>`, de uma montagem cujo processo morreu
     * antes do `finally`) e arquivos retirados (`<16 hex>.<ext>`, de uma entrega que não
     * aconteceu) — com mais de `$idadeMinima` segundos. Nada além desses dois nomes é tocado; link
     * simbólico nunca é seguido (no nível de cima nem é removido; dentro de uma área velha sai como
     * link, o alvo fica); erro de I/O numa entrada é pulado — limpar é cortesia, montar o próximo
     * .zip é o trabalho. Chamada por quem monta, antes de montar.
     *
     * @return int quantas entradas saíram
     *
     * @throws Exception\FalhaNoTemporario se o diretório do processo não puder ser preparado/provado privado
     */
    public static function limparSobras(string $finalidade, int $idadeMinima = self::IDADE_DE_SOBRA_SEGUNDOS): int
    {
        $diretorio = DiretorioTemporarioPrivado::doProcesso($finalidade)->caminho();
        $limite    = time() - $idadeMinima;
        $removidas = 0;

        foreach (scandir($diretorio) ?: [] as $entrada) {
            $caminho = $diretorio . '/' . $entrada;
            if (is_link($caminho)) {
                continue;
            }

            $ehArea     = preg_match('/^[0-9a-f]{16}$/', $entrada) === 1 && is_dir($caminho);
            $ehRetirado = preg_match('/^[0-9a-f]{16}\.[a-z0-9]{1,16}$/', $entrada) === 1 && is_file($caminho);
            if (!$ehArea && !$ehRetirado) {
                continue;
            }

            $modificado = @filemtime($caminho);
            if ($modificado === false || $modificado > $limite) {
                continue;
            }

            if ($ehArea ? self::apagarDiretorio($caminho) : @unlink($caminho)) {
                ++$removidas;
            }
        }

        return $removidas;
    }

    /** Apaga uma área velha inteira — e só ela: link lá dentro é removido como link, nunca seguido. */
    private static function apagarDiretorio(string $diretorio): bool
    {
        foreach (scandir($diretorio) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $caminho = $diretorio . '/' . $entrada;
            if (is_dir($caminho) && !is_link($caminho)) {
                self::apagarDiretorio($caminho);

                continue;
            }

            @unlink($caminho);
        }

        return @rmdir($diretorio);
    }
}
