<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Decorador de qualquer backend — inclusive o disco de verdade — que registra as chaves gravadas e
 * deixa o teste mexer no que acontece DEPOIS de uma gravação bem-sucedida.
 *
 * Existe para os casos que o {@see ArmazenamentoEmMemoria} não alcança: provar, contra o disco, que
 * o arquivo que um chamador gravou foi removido quando a operação falhou mais adiante (a limpeza
 * mira o disco, não o dublê), ou que uma falha posterior à escrita não consumiu a origem.
 *
 * `depoisDeGravar` recebe o resultado real e a ordem da gravação (1, 2, ...) e devolve o que o
 * chamador vai ver — o mesmo resultado, um resultado alterado, ou uma exceção. O arquivo já está
 * gravado quando ele roda.
 */
final class ArmazenamentoEspiao implements ArmazenamentoDeArquivos, MaterializadorDeArquivo
{
    /** @var list<ChaveDeArquivo> */
    public array $gravadas = [];

    /** @var (\Closure(ArquivoArmazenado, int): ArquivoArmazenado)|null */
    public ?\Closure $depoisDeGravar = null;

    /**
     * As chaves que chegaram a `excluir()` sem falha simulada, na ordem (E2.5).
     *
     * @var list<ChaveDeArquivo>
     */
    public array $excluidas = [];

    /**
     * Consultada antes de repassar cada `excluir()`: se devolver uma exceção, ela é lançada e o
     * backend real nem é chamado — o arquivo fica no disco.
     *
     * @var (\Closure(ChaveDeArquivo): ?\Throwable)|null
     */
    public ?\Closure $falhaAoExcluir = null;

    public function __construct(private readonly ArmazenamentoDeArquivos $real)
    {
    }

    /**
     * Materializar é REPASSE puro: o espião observa gravação e exclusão, não leitura. Desde a
     * E2.6C o reconciliador do Drive materializa por chave, e sem isto ele não caberia no dublê.
     */
    public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado
    {
        return $this->materializador()->paraLeitura($chave);
    }

    public function copiaGravavel(ChaveDeArquivo $chave): ArquivoTemporarioPossuido
    {
        return $this->materializador()->copiaGravavel($chave);
    }

    private function materializador(): MaterializadorDeArquivo
    {
        if (!$this->real instanceof MaterializadorDeArquivo) {
            throw new \LogicException('O backend decorado não materializa arquivo.');
        }

        return $this->real;
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $gravado          = $this->real->gravar($destino, $fonte);
        $this->gravadas[] = $gravado->chave;

        return $this->depoisDeGravar === null
            ? $gravado
            : ($this->depoisDeGravar)($gravado, \count($this->gravadas));
    }

    public function abrir(ChaveDeArquivo $chave): mixed
    {
        return $this->real->abrir($chave);
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        return $this->real->ler($chave);
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return $this->real->existe($chave);
    }

    public function excluir(ChaveDeArquivo $chave): void
    {
        $falha = $this->falhaAoExcluir === null ? null : ($this->falhaAoExcluir)($chave);
        if ($falha !== null) {
            throw $falha;
        }

        $this->real->excluir($chave);
        $this->excluidas[] = $chave;
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        return $this->real->metadados($chave);
    }
}
