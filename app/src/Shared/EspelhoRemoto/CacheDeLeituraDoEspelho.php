<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * A área local onde a leitura de recuperação deixa o que baixou do R2 (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md`, D3).
 *
 *  - **o nome do arquivo é o SHA-256 do conteúdo**: duas chaves com o mesmo conteúdo dividem o arquivo, e
 *    o nome já diz o que tem de estar dentro;
 *  - **escrita atômica**: o download vai para `.parcial-<aleatório>`, é conferido (tamanho e SHA) e só
 *    então ganha o nome final por `rename`. Arquivo que não confere nunca aparece com o nome final, e
 *    portanto nunca é entregue. Duas hidratações ao mesmo tempo da mesma chave escrevem cada uma o seu
 *    parcial; a última a renomear vence, e as duas versões são o mesmo conteúdo conferido;
 *  - **teto em bytes** (`ESPELHO_CACHE_MAX_BYTES`): depois de publicar, os arquivos menos usados saem
 *    até o total caber. "Menos usado" é o maior entre `atime` e `mtime`: cada acesso pelo cache toca o
 *    arquivo, então o critério vale mesmo em volume montado com `noatime`. O arquivo que acabou de ser
 *    publicado nunca sai na mesma rodada, mesmo sozinho maior que o teto (senão não haveria como
 *    entregá-lo);
 *  - **parcial órfão** (processo morto no meio do download) com mais de uma hora sai na próxima gravação.
 *
 * O cache é por instância e descartável: apagar o diretório inteiro só custa downloads.
 */
final class CacheDeLeituraDoEspelho
{
    public const IDADE_DO_PARCIAL_ORFAO_EM_SEGUNDOS = 3600;

    private const PREFIXO_DO_PARCIAL = '.parcial-';

    private readonly string $diretorio;

    public function __construct(
        string $diretorio,
        private readonly int $maximoDeBytes,
    ) {
        $this->diretorio = rtrim($diretorio, '/');
        if ($maximoDeBytes < 0) {
            throw new \InvalidArgumentException('O teto do cache de leitura não pode ser negativo.');
        }
    }

    /**
     * O arquivo do cache para este conteúdo, se já estiver lá com o tamanho certo (e o toca, para o
     * descarte por uso). Null quando não está.
     */
    public function caminhoDe(string $sha256, int $tamanhoBytes): ?string
    {
        self::exigirSha($sha256);
        $caminho = $this->diretorio . '/' . $sha256;
        clearstatcache(true, $caminho);

        if (!is_file($caminho) || @filesize($caminho) !== $tamanhoBytes) {
            return null;
        }

        @touch($caminho);

        return $caminho;
    }

    /**
     * Devolve o arquivo do cache para este conteúdo, baixando-o com `$baixar` quando ainda não está lá.
     *
     * `$baixar` recebe um stream gravável e escreve nele o conteúdo; o que ele escreveu só é publicado
     * depois de conferido aqui. Qualquer exceção de `$baixar` sobe como veio, sem deixar arquivo.
     *
     * @param callable(resource): void $baixar
     *
     * @throws FalhaNoEspelhoRemoto quando o conteúdo baixado não tem o tamanho ou o SHA esperados
     * @throws FalhaDeArmazenamento quando o diretório do cache não pode ser preparado ou escrito
     */
    public function hidratar(string $sha256, int $tamanhoBytes, callable $baixar): string
    {
        $existente = $this->caminhoDe($sha256, $tamanhoBytes);
        if ($existente !== null) {
            return $existente;
        }

        $this->garantirDiretorio();
        $this->limparParciaisOrfaos();

        $parcial = $this->baixarParaParcial($baixar);
        $this->exigirQueOParcialConfere($parcial, $sha256, $tamanhoBytes);

        $final = $this->diretorio . '/' . $sha256;
        if (!@rename($parcial, $final)) {
            @unlink($parcial);

            throw new FalhaDeArmazenamento('Não foi possível publicar o arquivo no cache de leitura do espelho.');
        }

        $this->descartarExcedente($sha256);

        return $final;
    }

    /** @param callable(resource): void $baixar */
    private function baixarParaParcial(callable $baixar): string
    {
        $parcial = $this->diretorio . '/' . self::PREFIXO_DO_PARCIAL . bin2hex(random_bytes(8));
        $stream  = @fopen($parcial, 'xb');
        if ($stream === false) {
            throw new FalhaDeArmazenamento('Não foi possível criar o arquivo parcial no cache de leitura do espelho.');
        }

        try {
            $baixar($stream);
            if (!fflush($stream)) {
                throw new FalhaDeArmazenamento('Não foi possível gravar no cache de leitura do espelho.');
            }
        } catch (\Throwable $e) {
            fclose($stream);
            @unlink($parcial);

            throw $e;
        }
        fclose($stream);

        return $parcial;
    }

    /** Confere tamanho e SHA do parcial; se não confere, ele sai e nada é publicado. */
    private function exigirQueOParcialConfere(string $parcial, string $sha256, int $tamanhoBytes): void
    {
        clearstatcache(true, $parcial);
        $medido = @hash_file('sha256', $parcial);
        if (@filesize($parcial) === $tamanhoBytes && $medido !== false && hash_equals($sha256, $medido)) {
            return;
        }

        @unlink($parcial);

        throw new FalhaNoEspelhoRemoto(sprintf('O conteúdo baixado do espelho não confere com o esperado (%d bytes, sha256 %s); nada foi publicado no cache.', $tamanhoBytes, $sha256));
    }

    /**
     * Stream de leitura de um arquivo devolvido por {@see hidratar()}.
     *
     * @return resource
     *
     * @throws FalhaDeArmazenamento quando o arquivo saiu do cache entre a hidratação e a leitura
     */
    public function abrir(string $caminho): mixed
    {
        $stream = @fopen($this->exigirPublicado($caminho), 'rb');
        if ($stream === false) {
            throw new FalhaDeArmazenamento('Não foi possível abrir o arquivo do cache de leitura do espelho.');
        }

        return $stream;
    }

    /** @throws FalhaDeArmazenamento */
    public function ler(string $caminho): string
    {
        $conteudo = @file_get_contents($this->exigirPublicado($caminho));
        if ($conteudo === false) {
            throw new FalhaDeArmazenamento('Não foi possível ler o arquivo do cache de leitura do espelho.');
        }

        return $conteudo;
    }

    /**
     * Copia um arquivo do cache para `$destino` (a cópia gravável), inteira.
     *
     * @throws FalhaNoTemporario quando a cópia não sai inteira (o original do cache não é tocado)
     */
    public function copiarPara(string $caminho, string $destino): void
    {
        $origem = $this->exigirPublicado($caminho);
        clearstatcache(true, $origem);
        $tamanho = @filesize($origem);
        if ($tamanho === false || !@copy($origem, $destino)) {
            throw new FalhaNoTemporario('Não foi possível copiar o arquivo do cache de leitura do espelho para o temporário.');
        }
        clearstatcache(true, $destino);
        if (@filesize($destino) !== $tamanho) {
            throw new FalhaNoTemporario('A cópia do arquivo do cache de leitura do espelho saiu incompleta.');
        }
    }

    /** Só arquivo publicado DESTE cache (nome = SHA): nada aqui abre caminho arbitrário. */
    private function exigirPublicado(string $caminho): string
    {
        if (\dirname($caminho) !== $this->diretorio || preg_match('/\A[0-9a-f]{64}\z/', basename($caminho)) !== 1) {
            throw new \InvalidArgumentException('Caminho fora do cache de leitura do espelho.');
        }

        return $caminho;
    }

    /** Remove os menos usados até o total caber no teto. Nunca remove `$protegido`, nem parciais. */
    private function descartarExcedente(string $protegido): void
    {
        $arquivos = [];
        $total    = 0;
        foreach ($this->entradas() as $nome) {
            if (preg_match('/\A[0-9a-f]{64}\z/', $nome) !== 1) {
                continue;
            }
            $info = @stat($this->diretorio . '/' . $nome);
            if ($info === false) {
                continue;
            }
            $arquivos[] = ['nome' => $nome, 'tamanho' => (int) $info['size'], 'uso' => max((int) $info['atime'], (int) $info['mtime'])];
            $total += (int) $info['size'];
        }

        if ($total <= $this->maximoDeBytes) {
            return;
        }

        usort($arquivos, static fn (array $a, array $b): int => [$a['uso'], $a['nome']] <=> [$b['uso'], $b['nome']]);
        foreach ($arquivos as $arquivo) {
            if ($total <= $this->maximoDeBytes) {
                return;
            }
            if ($arquivo['nome'] === $protegido) {
                continue;
            }
            if (@unlink($this->diretorio . '/' . $arquivo['nome'])) {
                $total -= $arquivo['tamanho'];
            }
        }
    }

    private function limparParciaisOrfaos(): void
    {
        $limite = time() - self::IDADE_DO_PARCIAL_ORFAO_EM_SEGUNDOS;
        foreach ($this->entradas() as $nome) {
            if (!str_starts_with($nome, self::PREFIXO_DO_PARCIAL)) {
                continue;
            }
            $caminho = $this->diretorio . '/' . $nome;
            $mtime   = @filemtime($caminho);
            if ($mtime !== false && $mtime < $limite) {
                @unlink($caminho);
            }
        }
    }

    /** @return list<string> */
    private function entradas(): array
    {
        $entradas = @scandir($this->diretorio);

        return $entradas === false ? [] : array_values(array_diff($entradas, ['.', '..']));
    }

    private function garantirDiretorio(): void
    {
        if (!is_dir($this->diretorio) && !@mkdir($this->diretorio, 0o750, true) && !is_dir($this->diretorio)) {
            throw new FalhaDeArmazenamento('Não foi possível preparar o diretório do cache de leitura do espelho.');
        }
    }

    private static function exigirSha(string $sha256): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('O SHA-256 tem de vir em hex minúsculo de 64 caracteres.');
        }
    }
}
