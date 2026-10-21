<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\DTO\CompromissoOutput as C;
use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Entity\Agenda\LegendaCor;

/**
 * Em que calendário um compromisso aparece: pelo tipo; sem tipo (agenda antiga), pela cor da legenda do
 * escritório (ou pelas cores padrão do formulário antigo, quando não há legendas); senão "Meus compromissos".
 * A grade e a contagem da lateral usam esta mesma regra, para nunca divergirem.
 */
final class CalendarioPelaCor
{
    /**
     * Cor da legenda => calendário, pelo nome da legenda.
     *
     * @param list<LegendaCor> $legendas
     *
     * @return array<string, string>
     */
    public function mapa(array $legendas): array
    {
        $mapa = [] !== $legendas ? [] : [strtolower(Evento::COR_AZUL) => C::CAL_AUD, strtolower(Evento::COR_VERDE) => C::CAL_PRAZO, strtolower(Evento::COR_AMARELO) => C::CAL_REUNIAO];
        foreach ($legendas as $legenda) {
            $nome = mb_strtolower((string) $legenda->getNome());
            $cal = match (true) {
                str_contains($nome, 'audi')  => C::CAL_AUD,
                str_contains($nome, 'reuni') => C::CAL_REUNIAO,
                str_contains($nome, 'prazo') => C::CAL_PRAZO,
                default                      => null,
            };
            if (null !== $cal) {
                $mapa[strtolower((string) $legenda->getCor())] ??= $cal;
            }
        }

        return $mapa;
    }

    /** @param array<string, string> $mapa */
    public function calendario(?TipoDeCompromisso $tipo, string $cor, array $mapa): string
    {
        return $tipo->value ?? $mapa[strtolower($cor)] ?? C::CAL_MEUS;
    }
}
