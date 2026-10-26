<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;

/**
 * O modelo escolhido pelo Master (`inteligencia_plataforma.modelo`). Sem a linha, vale o `IA_MODELO` do ambiente, que
 * virou só o valor de reserva. Valor gravado fora do {@see ModeloDeIa} (alguém mexeu no banco à mão) volta vazio: o
 * adaptador fica dormente, em vez de mandar à Anthropic um modelo que o painel não oferece (Fable e Mythos, por
 * exemplo, exigem retenção de 30 dias).
 */
final class ModeloDaPlataformaDeIa implements FonteDoModeloDeIa
{
    public function __construct(
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
        private readonly string $modeloDoAmbiente,
    ) {
    }

    public function modelo(): string
    {
        $gravada = $this->plataforma->gravada();
        if ($gravada === null) {
            return trim($this->modeloDoAmbiente);
        }

        return $gravada->modeloEscolhido()->value ?? '';
    }
}
