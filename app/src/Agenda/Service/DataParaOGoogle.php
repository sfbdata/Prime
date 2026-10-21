<?php

declare(strict_types=1);

namespace App\Agenda\Service;

/**
 * Tradução de uma data do sistema da Agenda para o evento da agenda do escritório no Google (spec agenda-google §9):
 * dia inteiro, sem ocupar o horário (`transparency: transparent`), título igual ao da grade e o aviso de só leitura.
 */
final class DataParaOGoogle
{
    private const AVISO = 'Data do sistema na Agenda do BlueJus. Para mudar, ajuste na Agenda do BlueJus: o que for mudado aqui volta como está lá.';

    /** `bjt<escritório>d<sha1 da chave>`: só 0-9 e a-f, dentro do alfabeto base32hex do Google. */
    public function idPara(int $tenantId, string $chave): string
    {
        return 'bjt' . $tenantId . 'd' . sha1($chave);
    }

    /** O id é de uma data do sistema nossa? Nunca vira compromisso importado. */
    public function ehIdDeData(string $googleId): bool
    {
        return 1 === preg_match('/^bjt\d+d[0-9a-f]{40}$/', $googleId);
    }

    /** @return array<string, mixed> o corpo do evento na API do Google */
    public function corpo(string $dia, string $titulo, ?string $observacao): array
    {
        $inicio = new \DateTimeImmutable($dia);

        return [
            'summary'      => $titulo,
            'description'  => null === $observacao || '' === trim($observacao) ? self::AVISO : self::AVISO . "\n" . trim($observacao),
            'location'     => '',
            'status'       => 'confirmed',
            'visibility'   => 'default',
            'transparency' => 'transparent',
            'start'        => ['date' => $inicio->format('Y-m-d')],
            'end'          => ['date' => $inicio->modify('+1 day')->format('Y-m-d')],
        ];
    }

    /** @param array<string, mixed> $item o evento como o Google devolve (ou o nosso corpo) */
    public function resumoDoItem(array $item): string
    {
        return (new ResumoDoEventoSoLeitura())->de($item);
    }
}
