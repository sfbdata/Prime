<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\LancamentoDeCustoRepository;
use App\Master\Service\ArquivoDeCustosCsv;
use App\Master\Service\RotulosDeCusto;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Master baixa todos os lançamentos de custo em CSV (desenho `csCsv`, spec master-custos D7). Só leitura. */
final class ExportarCustosUseCase
{
    public function __construct(
        private readonly LancamentoDeCustoRepository $lancamentos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function executar(): string
    {
        return ArquivoDeCustosCsv::gerar(
            $this->lancamentos->todos(),
            RotulosDeCusto::categorias($this->tradutor),
            RotulosDeCusto::origens($this->tradutor),
        );
    }
}
