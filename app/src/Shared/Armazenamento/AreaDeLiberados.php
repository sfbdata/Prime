<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * O disco da liberação (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.3 e §5.4): mede o arquivo
 * local, tira a cópia do lugar para `<uploads_dir>/.liberados/<lote>/` e a devolve.
 *
 * Mora no núcleo porque é aqui que chave vira caminho (D11): quem libera ou restaura endereça por
 * {@see ChaveDeArquivo} e pelo lote, e nunca conhece o caminho.
 *
 * Regras que nenhum método quebra:
 *  - **liberar e restaurar nunca apagam conteúdo**: o arquivo muda de lugar, e o `unlink` é o do nome
 *    de origem depois que o mesmo inode já tem o nome de destino (ou do temporário que esta classe
 *    criou). A única exceção é {@see apagarDosLiberados()}, que só a exclusão pedida chama (o usuário
 *    ou a purga apagou o documento, e a cópia liberada não pode ficar: LGPD);
 *  - **nunca sobrescreve**: destino existente é {@see MovimentoNoDisco::DESTINO_EXISTE}, e nada muda;
 *  - **mesmo sistema de arquivos**: o `rename` entre discos diferentes viraria cópia e apagamento,
 *    então é recusado antes;
 *  - **a medição não aquece o arquivo**: ler para o SHA mudaria o `atime` (com `relatime`), e o
 *    próximo lote veria um arquivo "acessado agora". O `atime` volta ao que era, se o arquivo ainda é
 *    o mesmo (mesmo inode e mesmo `mtime`).
 *
 * Liberar usa `rename` (um gravador que regrava a chave no meio cria um arquivo novo na origem, que
 * fica). Devolver usa `link` e depois `unlink` da cópia nos liberados: o `link` falha se o original já
 * existe, então nunca sobrescreve, nem numa corrida com um upload.
 */
final readonly class AreaDeLiberados implements DiscoDaLiberacao
{
    private const MODO_DOS_DIRETORIOS = 0o750;

    private const PREFIXO_DO_PARCIAL = '.restaurando-';

    public function __construct(
        private ResolvedorDeCaminhoLocal $resolvedor,
    ) {
    }

    /** Tamanho, `mtime` e `atime` do arquivo no lugar dele; null quando não há arquivo regular ali. */
    public function noDisco(ChaveDeArquivo $chave): ?ArquivoNoDisco
    {
        return $this->medir($this->resolvedor->caminhoDe($chave));
    }

    /** O arquivo está nos liberados deste lote? */
    public function estaNosLiberados(ChaveDeArquivo $chave, string $lote): bool
    {
        return $this->medir($this->resolvedor->caminhoNosLiberados($chave, $lote)) !== null;
    }

    /**
     * SHA-256 do arquivo no lugar dele, sem mudar o `atime`.
     *
     * @throws ArquivoNaoEncontrado
     * @throws FalhaDeArmazenamento
     */
    public function sha256NoDisco(ChaveDeArquivo $chave): string
    {
        return $this->sha256Preservando($this->resolvedor->caminhoDe($chave), $chave);
    }

    /**
     * SHA-256 da cópia nos liberados do lote, sem mudar o `atime`.
     *
     * @throws ArquivoNaoEncontrado
     * @throws FalhaDeArmazenamento
     */
    public function sha256NosLiberados(ChaveDeArquivo $chave, string $lote): string
    {
        return $this->sha256Preservando($this->resolvedor->caminhoNosLiberados($chave, $lote), $chave);
    }

    /**
     * Prova um download: `$escrever` grava o conteúdo num temporário privado, e o que chegou é medido
     * aqui (SHA-256 e tamanho), independente de quem baixou ter conferido. O temporário sai no fim.
     *
     * @param \Closure(resource): void $escrever
     *
     * @return array{sha256: string, tamanhoBytes: int}
     */
    public function medirDownload(\Closure $escrever): array
    {
        $area = AreaTemporariaPrivada::criar('liberacao');
        try {
            $caminho = $area->caminho() . '/download';
            $destino = @fopen($caminho, 'xb');
            if ($destino === false) {
                throw new FalhaDeArmazenamento('Não foi possível criar o temporário do download.');
            }
            try {
                $escrever($destino);
            } finally {
                fclose($destino);
            }
            clearstatcache(true, $caminho);
            $tamanho = @filesize($caminho);
            if ($tamanho === false) {
                throw new FalhaDeArmazenamento('Não foi possível medir o temporário do download.');
            }

            return ['sha256' => Sha256DeArquivo::deArquivoLocal($caminho), 'tamanhoBytes' => $tamanho];
        } finally {
            $area->liberar();
        }
    }

    /**
     * Tira o arquivo do lugar para os liberados do lote, por `rename`. Destino já ocupado: nada muda.
     *
     * @throws FalhaDeArmazenamento quando não dá para criar o diretório, os dois lados estão em
     *                              sistemas de arquivos diferentes, ou o `rename` falha
     */
    public function moverParaLiberados(ChaveDeArquivo $chave, string $lote): MovimentoNoDisco
    {
        $origem  = $this->resolvedor->caminhoDe($chave);
        $destino = $this->resolvedor->caminhoNosLiberados($chave, $lote);

        $daOrigem = $this->lstatRegular($origem);
        if ($daOrigem === null) {
            return MovimentoNoDisco::ORIGEM_AUSENTE;
        }
        $this->garantirDiretorio(\dirname($destino));
        if ($this->existeAlgo($destino)) {
            return MovimentoNoDisco::DESTINO_EXISTE;
        }
        $this->exigirMesmoDisco($daOrigem['dev'], \dirname($destino));

        if (!@rename($origem, $destino)) {
            throw new FalhaDeArmazenamento(sprintf('Não foi possível mover %s para os liberados.', $chave->comoTexto()));
        }

        return MovimentoNoDisco::MOVIDO;
    }

    /**
     * Devolve a cópia dos liberados do lote ao lugar original, só se o original não existe.
     *
     * @throws FalhaDeArmazenamento
     */
    public function devolverDosLiberados(ChaveDeArquivo $chave, string $lote): MovimentoNoDisco
    {
        $liberado = $this->resolvedor->caminhoNosLiberados($chave, $lote);
        $original = $this->resolvedor->caminhoDe($chave);

        if ($this->lstatRegular($liberado) === null) {
            return MovimentoNoDisco::ORIGEM_AUSENTE;
        }
        $this->garantirDiretorio(\dirname($original));

        return $this->publicarSemSobrescrever($liberado, $original, $chave);
    }

    /**
     * Grava no lugar original o que `$escrever` produz (o download do espelho), conferido contra o
     * SHA-256 e o tamanho esperados, sem sobrescrever. O parcial nasce em `.liberados/.parciais`, no
     * mesmo sistema de arquivos e fora do diretório dos documentos, e só ganha o nome final depois de
     * conferido. Conteúdo que não confere: {@see MovimentoNoDisco::CONTEUDO_DIVERGENTE}, nada publicado.
     *
     * @param \Closure(resource): void $escrever
     *
     * @throws FalhaDeArmazenamento quando o disco falha
     */
    public function publicarNoOriginal(ChaveDeArquivo $chave, \Closure $escrever, string $sha256, int $tamanhoBytes): MovimentoNoDisco
    {
        $original = $this->resolvedor->caminhoDe($chave);
        if ($this->existeAlgo($original)) {
            return MovimentoNoDisco::DESTINO_EXISTE;
        }
        $this->garantirDiretorio(\dirname($original));
        $parciais = $this->resolvedor->diretorioDosParciaisDaLiberacao();
        $this->garantirDiretorio($parciais);

        $parcial = $parciais . '/' . self::PREFIXO_DO_PARCIAL . bin2hex(random_bytes(8));
        $saida   = @fopen($parcial, 'xb');
        if ($saida === false) {
            throw new FalhaDeArmazenamento(sprintf('Não foi possível criar o parcial de %s.', $chave->comoTexto()));
        }

        try {
            try {
                $escrever($saida);
            } finally {
                fclose($saida);
            }
            clearstatcache(true, $parcial);
            if (@filesize($parcial) !== $tamanhoBytes || !hash_equals($sha256, Sha256DeArquivo::deArquivoLocal($parcial))) {
                return MovimentoNoDisco::CONTEUDO_DIVERGENTE;
            }

            return $this->publicarSemSobrescrever($parcial, $original, $chave);
        } finally {
            if ($this->existeAlgo($parcial)) {
                @unlink($parcial);
            }
        }
    }

    /**
     * Apaga a cópia da chave nos liberados do lote. Só a exclusão pedida chama (o documento saiu do
     * sistema); ausente conta como apagada.
     *
     * @throws FalhaDeArmazenamento quando a cópia continua lá
     */
    public function apagarDosLiberados(ChaveDeArquivo $chave, string $lote): void
    {
        $copia = $this->resolvedor->caminhoNosLiberados($chave, $lote);
        if ($this->lstatRegular($copia) === null) {
            return;
        }
        if (!@unlink($copia) && $this->existeAlgo($copia)) {
            throw new FalhaDeArmazenamento(sprintf('Não foi possível apagar a cópia liberada de %s.', $chave->comoTexto()));
        }
    }

    /** `link` (que falha com o destino ocupado) e depois o `unlink` do nome de origem. */
    private function publicarSemSobrescrever(string $origem, string $destino, ChaveDeArquivo $chave): MovimentoNoDisco
    {
        if ($this->existeAlgo($destino)) {
            return MovimentoNoDisco::DESTINO_EXISTE;
        }
        if (!@link($origem, $destino)) {
            clearstatcache(true, $destino);
            if ($this->existeAlgo($destino)) {
                return MovimentoNoDisco::DESTINO_EXISTE;
            }

            throw new FalhaDeArmazenamento(sprintf('Não foi possível devolver %s ao lugar original.', $chave->comoTexto()));
        }
        if (!@unlink($origem)) {
            throw new FalhaDeArmazenamento(sprintf('%s voltou ao lugar, mas o nome de origem ficou.', $chave->comoTexto()));
        }

        return MovimentoNoDisco::MOVIDO;
    }

    private function sha256Preservando(string $caminho, ChaveDeArquivo $chave): string
    {
        $antes = $this->lstatRegular($caminho);
        if ($antes === null) {
            throw ArquivoNaoEncontrado::para($chave);
        }

        $sha256 = Sha256DeArquivo::deArquivoLocal($caminho);

        $depois = $this->lstatRegular($caminho);
        if ($depois !== null && $depois['ino'] === $antes['ino'] && $depois['mtime'] === $antes['mtime'] && $depois['atime'] !== $antes['atime']) {
            @touch($caminho, $antes['mtime'], $antes['atime']);
            clearstatcache(true, $caminho);
        }

        return $sha256;
    }

    private function medir(string $caminho): ?ArquivoNoDisco
    {
        $stat = $this->lstatRegular($caminho);
        if ($stat === null) {
            return null;
        }

        return new ArquivoNoDisco(
            $stat['size'],
            (new \DateTimeImmutable())->setTimestamp($stat['mtime']),
            (new \DateTimeImmutable())->setTimestamp($stat['atime']),
        );
    }

    /**
     * `lstat` de um arquivo regular (link simbólico não conta); null quando não há arquivo regular.
     *
     * @return array{dev: int, ino: int, size: int, mtime: int, atime: int}|null
     */
    private function lstatRegular(string $caminho): ?array
    {
        clearstatcache(true, $caminho);
        $stat = @lstat($caminho);
        if ($stat === false || ($stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }

        return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'], 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'atime' => (int) $stat['atime']];
    }

    /** @phpstan-impure o disco muda entre duas perguntas */
    private function existeAlgo(string $caminho): bool
    {
        clearstatcache(true, $caminho);

        return @lstat($caminho) !== false;
    }

    private function exigirMesmoDisco(int $dispositivoDaOrigem, string $diretorioDoDestino): void
    {
        $stat = @stat($diretorioDoDestino);
        if ($stat === false || (int) $stat['dev'] !== $dispositivoDaOrigem) {
            throw new FalhaDeArmazenamento('Os liberados não estão no mesmo sistema de arquivos do original: o movimento viraria cópia.');
        }
    }

    private function garantirDiretorio(string $diretorio): void
    {
        if (is_dir($diretorio)) {
            return;
        }
        if (!@mkdir($diretorio, self::MODO_DOS_DIRETORIOS, true) && !is_dir($diretorio)) {
            throw new FalhaDeArmazenamento('Não foi possível criar o diretório da liberação.');
        }
    }
}
