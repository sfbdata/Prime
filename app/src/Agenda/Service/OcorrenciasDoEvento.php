<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Entity\Agenda\Evento;

/**
 * Ocorrências de um evento num período. Mesma regra da agenda antiga
 * (EventoRepository::expandirEventosRecorrentes): diário, semanal, mensal ou anual a partir do
 * início, até o fim da recorrência, no máximo 366 ocorrências.
 */
final class OcorrenciasDoEvento
{
    private const MAX = 366;

    /** @return list<array{inicio: \DateTimeImmutable, fim: \DateTimeImmutable}> */
    public function noPeriodo(Evento $evento, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $inicio = $evento->getDataInicio();
        $fim = $evento->getDataFim() ?? $inicio;
        if (null === $inicio || null === $fim) {
            return [];
        }
        if (!$evento->isRecorrente()) {
            return $inicio <= $ate && $fim >= $de ? [['inicio' => $inicio, 'fim' => $fim]] : [];
        }

        return $this->recorrentes($evento, $inicio, $inicio->diff($fim), $de, $ate);
    }

    /** @return list<array{inicio: \DateTimeImmutable, fim: \DateTimeImmutable}> */
    private function recorrentes(Evento $evento, \DateTimeImmutable $atual, \DateInterval $duracao, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $limite = $evento->getFimRecorrencia();
        $limite = null === $limite || $limite > $ate ? $ate : $limite;
        $saida = [];
        for ($n = 0; null !== $atual && $atual <= $limite && $n < self::MAX; ++$n) {
            if ($atual >= $de) {
                $saida[] = ['inicio' => $atual, 'fim' => $atual->add($duracao)];
            }
            $atual = $this->proxima($atual, $evento->getTipoRecorrencia());
        }

        return $saida;
    }

    private function proxima(\DateTimeImmutable $data, ?string $tipo): ?\DateTimeImmutable
    {
        return match ($tipo) {
            'diario'  => $data->modify('+1 day'),
            'semanal' => $data->modify('+1 week'),
            'mensal'  => $data->modify('+1 month'),
            'anual'   => $data->modify('+1 year'),
            default   => null,
        };
    }
}
