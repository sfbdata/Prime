<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O que uma operação gravou de novo e o que ela deixou sem uso. Os novos saem se a transação comprovadamente não
 * confirmou (`TransacaoComArquivoNovo`); os antigos, só depois do COMMIT (`RemocaoAposTransacao`).
 */
final class TrocaDeArquivos
{
    /** @var list<ChaveDeArquivo> */
    private array $novas = [];

    /** @var list<ChaveDeArquivo> */
    private array $antigas = [];

    public function gravada(ChaveDeArquivo $chave): void
    {
        $this->novas[] = $chave;
    }

    public function semUso(?ChaveDeArquivo $chave): void
    {
        if ($chave !== null) {
            $this->antigas[] = $chave;
        }
    }

    /** @return list<ChaveDeArquivo> */
    public function novas(): array
    {
        return $this->novas;
    }

    /** @return list<ChaveDeArquivo> */
    public function antigas(): array
    {
        return $this->antigas;
    }
}
