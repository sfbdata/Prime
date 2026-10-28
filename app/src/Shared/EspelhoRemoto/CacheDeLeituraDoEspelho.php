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
 *    portanto nunca é entregue;
 *  - **um download por conteúdo**: a hidratação segura um `flock` exclusivo em `.trava-<sha>`. Quem
 *    chega enquanto outro processo baixa o mesmo conteúdo espera a trava e lê o que ele publicou, sem
 *    ir ao R2 de novo;
 *  - **teto em bytes** (`ESPELHO_CACHE_MAX_BYTES`, com os parciais em curso contados): depois de
 *    publicar, os arquivos menos usados saem até o total caber. "Menos usado" é o maior entre `atime` e
 *    `mtime`: cada acesso pelo cache toca o arquivo, então o critério vale mesmo em volume montado com
 *    `noatime`;
 *  - **arquivo usado nos últimos 30 minutos nunca sai**, mesmo passando do teto: quem recebeu o
 *    caminho emprestado ainda vai lê-lo (o `ZipArchive` só lê no `close()`, a `BinaryFileResponse`
 *    só abre no `sendContent()`, um vídeo pede vários Range). Por isso o cache **pode passar do teto
 *    temporariamente**; o excedente sai na primeira gravação depois que o uso esfria. O recém-publicado
 *    é sempre recente, e fica mesmo sozinho maior que o teto;
 *  - **parcial órfão** (processo morto no meio do download) e trava velha com mais de uma hora saem na
 *    próxima gravação.
 *
 * O cache é por instância e descartável: apagar o diretório inteiro só custa downloads.
 */
final class CacheDeLeituraDoEspelho
{
    public const IDADE_DO_PARCIAL_ORFAO_EM_SEGUNDOS = 3600;

    /** Arquivo usado há menos que isto não sai no descarte, mesmo passando do teto. */
    public const PROTECAO_DE_USO_RECENTE_EM_SEGUNDOS = 1800;

    private const PREFIXO_DO_PARCIAL = '.parcial-';

    private const PREFIXO_DA_TRAVA = '.trava-';

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
        $trava = $this->travar($sha256);

        try {
            // Outro processo pode ter publicado enquanto este esperava a trava.
            $existente = $this->caminhoDe($sha256, $tamanhoBytes);
            if ($existente !== null) {
                return $existente;
            }

            $this->limparOrfaos();
            $parcial = $this->baixarParaParcial($baixar);
            $this->exigirQueOParcialConfere($parcial, $sha256, $tamanhoBytes);

            $final = $this->diretorio . '/' . $sha256;
            if (!@rename($parcial, $final)) {
                @unlink($parcial);

                throw new FalhaDeArmazenamento('Não foi possível publicar o arquivo no cache de leitura do espelho.');
            }

            $this->descartarExcedente();

            return $final;
        } finally {
            flock($trava, \LOCK_UN);
            fclose($trava);
        }
    }

    /**
     * Trava exclusiva do conteúdo, por `flock` (espera quem já está baixando). O arquivo da trava fica
     * no diretório: apagá-lo ao destravar abriria a corrida de dois processos com travas em inodes
     * diferentes. A limpeza dos velhos é a de {@see limparOrfaos()}.
     *
     * @return resource
     */
    private function travar(string $sha256): mixed
    {
        $caminho = $this->diretorio . '/' . self::PREFIXO_DA_TRAVA . $sha256;
        $trava   = @fopen($caminho, 'c');
        if ($trava === false) {
            throw new FalhaDeArmazenamento('Não foi possível criar a trava no cache de leitura do espelho.');
        }
        if (!flock($trava, \LOCK_EX)) {
            fclose($trava);

            throw new FalhaDeArmazenamento('Não foi possível obter a trava no cache de leitura do espelho.');
        }
        @touch($caminho);

        return $trava;
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

    /**
     * Remove os menos usados até o total caber no teto. Os parciais em curso contam no total, mas não
     * saem aqui; arquivo usado nos últimos {@see self::PROTECAO_DE_USO_RECENTE_EM_SEGUNDOS} também não.
     */
    private function descartarExcedente(): void
    {
        $candidatos = [];
        $total      = 0;
        $recente    = time() - self::PROTECAO_DE_USO_RECENTE_EM_SEGUNDOS;
        foreach ($this->entradas() as $nome) {
            $publicado = preg_match('/\A[0-9a-f]{64}\z/', $nome) === 1;
            if (!$publicado && !str_starts_with($nome, self::PREFIXO_DO_PARCIAL)) {
                continue;
            }
            $info = @stat($this->diretorio . '/' . $nome);
            if ($info === false) {
                continue;
            }
            $total += (int) $info['size'];
            $uso    = max((int) $info['atime'], (int) $info['mtime']);
            if ($publicado && $uso < $recente) {
                $candidatos[] = ['nome' => $nome, 'tamanho' => (int) $info['size'], 'uso' => $uso];
            }
        }

        usort($candidatos, static fn (array $a, array $b): int => [$a['uso'], $a['nome']] <=> [$b['uso'], $b['nome']]);
        foreach ($candidatos as $arquivo) {
            if ($total <= $this->maximoDeBytes) {
                return;
            }
            if (@unlink($this->diretorio . '/' . $arquivo['nome'])) {
                $total -= $arquivo['tamanho'];
            }
        }
    }

    private function limparOrfaos(): void
    {
        $limite = time() - self::IDADE_DO_PARCIAL_ORFAO_EM_SEGUNDOS;
        foreach ($this->entradas() as $nome) {
            if (!str_starts_with($nome, self::PREFIXO_DO_PARCIAL) && !str_starts_with($nome, self::PREFIXO_DA_TRAVA)) {
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
