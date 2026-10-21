<?php

declare(strict_types=1);

namespace App\Agenda\Service;

/**
 * Resumo de um evento só leitura que o BlueJus põe na agenda do escritório no Google (prazos, datas do sistema):
 * título, descrição, local, início e fim normalizados. O que o Google devolve do nosso envio (hora com -03:00, data)
 * dá o mesmo resumo do corpo enviado: é eco. Mudou o resumo, foi mexido lá.
 */
final class ResumoDoEventoSoLeitura
{
    /** @param array<string, mixed> $item o evento como o Google devolve (ou o nosso corpo) */
    public function de(array $item): string
    {
        return hash('sha256', (string) json_encode([
            (string) ($item['summary'] ?? ''),
            (string) ($item['description'] ?? ''),
            (string) ($item['location'] ?? ''),
            $this->ponta($item['start'] ?? null),
            $this->ponta($item['end'] ?? null),
        ]));
    }

    /** Início ou fim comparável: dia inteiro pela data; com hora, na hora de parede de Brasília. */
    private function ponta(mixed $ponta): string
    {
        if (!is_array($ponta)) {
            return '';
        }
        if (is_string($ponta['date'] ?? null)) {
            return 'd:' . $ponta['date'];
        }
        if (!is_string($ponta['dateTime'] ?? null)) {
            return '';
        }
        try {
            $fuso = new \DateTimeZone(CompromissoNoGoogle::FUSO);
            $momento = new \DateTimeImmutable($ponta['dateTime'], is_string($ponta['timeZone'] ?? null) ? new \DateTimeZone($ponta['timeZone']) : $fuso);

            return 'h:' . $momento->setTimezone($fuso)->format('Y-m-d\TH:i:s');
        } catch (\Exception) {
            return '';
        }
    }
}
