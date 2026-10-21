<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Pasta\Entity\PastaPrazo;

/**
 * Tradução de um prazo ou audiência de pasta para o evento da agenda do escritório no Google (spec agenda-google
 * §7): título igual ao da grade, com hora uma hora a partir dela, sem hora dia inteiro, e o aviso de só leitura.
 * O resumo é o do corpo normalizado (o que o Google devolve do nosso envio dá o mesmo resumo: é eco).
 */
final class PrazoParaOGoogle
{
    public function idPara(PastaPrazo $prazo): string
    {
        return 'bjt' . $prazo->getTenant()->getId() . 'p' . $prazo->getId();
    }

    /** O id é de um prazo nosso (`bjt<escritório>p<prazo>`)? Nunca vira compromisso importado. */
    public function ehIdDePrazo(string $googleId): bool
    {
        return 1 === preg_match('/^bjt\d+p\d+$/', $googleId);
    }

    /** @return array<string, mixed> o corpo do evento na API do Google */
    public function corpo(PastaPrazo $prazo): array
    {
        return [
            'summary'     => $this->titulo($prazo),
            'description' => $this->descricao($prazo),
            'location'    => '',
            'status'      => 'confirmed',
            'visibility'  => 'default',
        ] + $this->quando($prazo);
    }

    public function resumo(PastaPrazo $prazo): string
    {
        return $this->resumoDoItem($this->corpo($prazo));
    }

    /** @param array<string, mixed> $item o evento como o Google devolve (ou o nosso corpo) */
    public function resumoDoItem(array $item): string
    {
        return hash('sha256', (string) json_encode([
            (string) ($item['summary'] ?? ''),
            (string) ($item['description'] ?? ''),
            (string) ($item['location'] ?? ''),
            $this->ponta($item['start'] ?? null),
            $this->ponta($item['end'] ?? null),
        ]));
    }

    private function titulo(PastaPrazo $prazo): string
    {
        $nome = $prazo->isAudiencia() ? 'Audiência' : ('' !== $prazo->getTitulo() ? $prazo->getTitulo() : 'Prazo');

        return $nome . ' · Pasta ' . $prazo->getPasta()->getNup();
    }

    private function descricao(PastaPrazo $prazo): string
    {
        $texto = ($prazo->isAudiencia() ? 'Audiência' : 'Prazo') . ' da pasta ' . $prazo->getPasta()->getNup()
            . ' no BlueJus. Para mudar, abra a pasta no BlueJus: o que for mudado aqui volta como está lá.';
        $responsavel = trim((string) $prazo->getResponsavel());

        return '' === $responsavel ? $texto : $texto . "\nResponsável: " . $responsavel;
    }

    /** @return array{start: array<string, string>, end: array<string, string>} */
    private function quando(PastaPrazo $prazo): array
    {
        $dia = $prazo->getData();
        $hora = (string) $prazo->getHora();
        if (1 !== preg_match('/^\d{2}:\d{2}$/', $hora)) {
            return ['start' => ['date' => $dia->format('Y-m-d')], 'end' => ['date' => $dia->modify('+1 day')->format('Y-m-d')]];
        }
        $inicio = new \DateTimeImmutable($dia->format('Y-m-d') . ' ' . $hora);

        return [
            'start' => ['dateTime' => $inicio->format('Y-m-d\TH:i:s'), 'timeZone' => CompromissoNoGoogle::FUSO],
            'end'   => ['dateTime' => $inicio->modify('+1 hour')->format('Y-m-d\TH:i:s'), 'timeZone' => CompromissoNoGoogle::FUSO],
        ];
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
