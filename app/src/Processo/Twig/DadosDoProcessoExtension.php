<?php

declare(strict_types=1);

namespace App\Processo\Twig;

use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosDoProcessoNoCartao;
use App\Processo\Entity\Processo;
use App\Processo\Service\MontadorDosDadosDoProcesso;
use App\Processo\Service\ResumoDoProcesso;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `dados_do_processo(proc, pasta)`: o cartão do processo pela regra do desenho (manual × base, selo,
 * marcas, linha de fonte; P5b). Função Twig pelo mesmo motivo das notas técnicas: o parcial
 * `pasta/_processos_vinculados.html.twig` é renderizado em vários lugares só com a `pasta`.
 */
final class DadosDoProcessoExtension extends AbstractExtension
{
    public function __construct(
        private readonly MontadorDosDadosDoProcesso $montador,
        private readonly ResumoDoProcesso $resumo,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dados_do_processo', [$this, 'dados']),
            // P5d: as linhas do "Copiar/Imprimir resumo", as mesmas do PDF do servidor.
            new TwigFunction('resumo_do_processo', [$this->resumo, 'texto']),
        ];
    }

    public function dados(Processo $processo, ?Pasta $pasta = null): DadosDoProcessoNoCartao
    {
        return $this->montador->montar($processo, $pasta);
    }
}
