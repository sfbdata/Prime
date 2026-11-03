<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\Armazenamento\ArquivoNoDisco;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiscoDaLiberacao;
use App\Shared\Armazenamento\MovimentoNoDisco;

/**
 * O disco da liberação de verdade, com ganchos para os testes provocarem o que acontece entre dois
 * passos: regravação no meio, falha ao mover, falha depois de mover.
 */
final class DiscoDaLiberacaoComGanchos implements DiscoDaLiberacao
{
    /** @var (\Closure(ChaveDeArquivo, string): void)|null roda antes de mover; pode lançar */
    public ?\Closure $antesDeMover = null;

    /** @var (\Closure(ChaveDeArquivo, string): void)|null roda depois de mover */
    public ?\Closure $depoisDeMover = null;

    /** @var (\Closure(ChaveDeArquivo, string): void)|null roda antes de medir a cópia nos liberados; pode lançar */
    public ?\Closure $antesDeMedirNosLiberados = null;

    public function __construct(private readonly DiscoDaLiberacao $real)
    {
    }

    public function noDisco(ChaveDeArquivo $chave): ?ArquivoNoDisco
    {
        return $this->real->noDisco($chave);
    }

    public function estaNosLiberados(ChaveDeArquivo $chave, string $lote): bool
    {
        return $this->real->estaNosLiberados($chave, $lote);
    }

    public function sha256NoDisco(ChaveDeArquivo $chave): string
    {
        return $this->real->sha256NoDisco($chave);
    }

    public function sha256NosLiberados(ChaveDeArquivo $chave, string $lote): string
    {
        if ($this->antesDeMedirNosLiberados !== null) {
            ($this->antesDeMedirNosLiberados)($chave, $lote);
        }

        return $this->real->sha256NosLiberados($chave, $lote);
    }

    public function medirDownload(\Closure $escrever): array
    {
        return $this->real->medirDownload($escrever);
    }

    public function moverParaLiberados(ChaveDeArquivo $chave, string $lote): MovimentoNoDisco
    {
        if ($this->antesDeMover !== null) {
            ($this->antesDeMover)($chave, $lote);
        }
        $movimento = $this->real->moverParaLiberados($chave, $lote);
        if ($this->depoisDeMover !== null) {
            ($this->depoisDeMover)($chave, $lote);
        }

        return $movimento;
    }

    public function devolverDosLiberados(ChaveDeArquivo $chave, string $lote): MovimentoNoDisco
    {
        return $this->real->devolverDosLiberados($chave, $lote);
    }

    public function publicarNoOriginal(ChaveDeArquivo $chave, \Closure $escrever, string $sha256, int $tamanhoBytes): MovimentoNoDisco
    {
        return $this->real->publicarNoOriginal($chave, $escrever, $sha256, $tamanhoBytes);
    }

    public function apagarDosLiberados(ChaveDeArquivo $chave, string $lote): void
    {
        $this->real->apagarDosLiberados($chave, $lote);
    }
}
