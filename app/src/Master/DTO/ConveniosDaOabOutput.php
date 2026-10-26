<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Entity\ConvenioDaUf;

/**
 * Os 27 estados com o convênio OAB de cada um e quantas contas o estado tem (desenho 05 Perfis 1.2 VS 25.10.26,
 * painel "Convênio OAB por estado"). `modo`: `livre` (sem linha, grátis), `dif` ou `sem`.
 */
final class ConveniosDaOabOutput
{
    /**
     * @param array<string, ConvenioDaUf> $convenios sigla => convênio não gratuito
     * @param array<string, int>          $contas     sigla => contas do estado
     */
    public function __construct(private readonly array $convenios, private readonly array $contas)
    {
    }

    /** @return array{estados: list<array{uf: string, modo: string, valor: float, desde: ?string, motivo: ?string, contas: int}>} */
    public function paraArray(): array
    {
        $estados = [];
        foreach (\App\Master\Service\LocalizacaoDaPessoa::UFS as $uf) {
            $c = $this->convenios[$uf] ?? null;
            $estados[] = [
                'uf' => $uf,
                'modo' => $c?->getModo() ?? 'livre',
                'valor' => $c?->getValor() ?? 0.0,
                'desde' => $c?->getDesde()->format('Y-m-d'),
                'motivo' => $c?->getMotivo(),
                'contas' => $this->contas[$uf] ?? 0,
            ];
        }

        return ['estados' => $estados];
    }
}
