<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * Um arquivo temporário que **é nosso**: tem ciclo de vida explícito e seu cleanup apaga (D9).
 *
 * O par de {@see ArquivoEmprestado}. A diferença não está num campo — está na classe: esta tem
 * `liberar()` e um destrutor que remove; a outra não tem nem um nem outro.
 *
 * ## Só pode possuir o que ele mesmo criou
 *
 * Não há construtor público que aceite um caminho qualquer. A única porta é {@see criarEm()}, que
 * **cria** o arquivo. Isso fecha estruturalmente o pior acidente possível — construir um
 * "possuído" apontando para um arquivo de produção e deixá-lo sair de escopo.
 *
 * ## Cleanup
 *
 * `liberar()` é idempotente e pode ser chamado à vontade. O destrutor chama `liberar()` como rede
 * de segurança para o caminho de exceção, mas o certo é liberar explicitamente, em `finally` —
 * destrutor em PHP roda quando o GC decide, e num processo longo isso pode demorar.
 *
 * Ele nunca apaga nada além do próprio caminho, e nunca segue symlink para fora dele.
 */
final class ArquivoTemporarioPossuido
{
    private bool $liberado = false;

    private function __construct(
        private readonly string $caminho,
    ) {
    }

    /**
     * Cria um arquivo vazio dentro de `$diretorio` e assume a propriedade dele.
     *
     * Criar em vez de receber é o ponto: garante que o caminho possuído nasceu aqui e não é o de
     * um arquivo persistido.
     */
    public static function criarEm(string $diretorio, string $prefixo = 'jusprime-'): self
    {
        if (!is_dir($diretorio) && !@mkdir($diretorio, 0o755, true) && !is_dir($diretorio)) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível preparar o diretório temporário %s.', $diretorio),
            );
        }

        $caminho = @tempnam($diretorio, $prefixo);
        if ($caminho === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível criar arquivo temporário em %s.', $diretorio),
            );
        }

        return new self($caminho);
    }

    /** Caminho local gravável. Some quando `liberar()` for chamado — não guarde a string. */
    public function caminho(): string
    {
        if ($this->liberado) {
            throw new FalhaDeArmazenamento('Este temporário já foi liberado.');
        }

        return $this->caminho;
    }

    public function foiLiberado(): bool
    {
        return $this->liberado;
    }

    /** Idempotente. Remove SOMENTE o próprio caminho. */
    public function liberar(): void
    {
        if ($this->liberado) {
            return;
        }

        $this->liberado = true;

        if (is_file($this->caminho)) {
            @unlink($this->caminho);
        }
    }

    /** Rede de segurança para o caminho de exceção; o certo é `liberar()` em `finally`. */
    public function __destruct()
    {
        $this->liberar();
    }
}
