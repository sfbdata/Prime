<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Entity\Evento;

/**
 * Tradução entre o compromisso da Agenda e o evento do Google Agenda, nos dois sentidos (entrega 6).
 * A Agenda grava hora de parede de Brasília; o Google recebe a mesma hora com `timeZone`. Dia inteiro vai
 * com `date` e fim exclusivo. "Só eu" vai como `private`. A cópia que vai à agenda de um participante leva no
 * começo da descrição quem é o dono e que ali é só leitura (modelo do dono de 20/10/2026, decisão b).
 */
final class CompromissoNoGoogle
{
    public const FUSO = 'America/Sao_Paulo';
    public const REPETICAO_NAO_SUPORTADA = 'Repetição do Google Agenda não suportada aqui.';
    /**
     * Resumo de quem veio do Google com repetição que a Agenda não representa: não volta ao Google (voltaria
     * sem a repetição e achataria a série de lá). O Google continua atualizando aqui.
     */
    public const SO_DO_GOOGLE = 'so-do-google';
    private const FREQUENCIAS = ['diario' => 'DAILY', 'semanal' => 'WEEKLY', 'mensal' => 'MONTHLY', 'anual' => 'YEARLY'];
    private const CHAVES_ACEITAS = ['FREQ', 'UNTIL', 'WKST', 'BYDAY', 'BYMONTHDAY', 'BYMONTH'];
    private const DIAS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    /**
     * Id nosso no Google (alfabeto base32hex: 0-9 e a-v): enviar duas vezes não cria dois eventos. O mesmo id
     * em todas as agendas (o id é único por agenda).
     */
    public function idPara(Evento $evento): string
    {
        return 'bjt' . $evento->getTenant()?->getId() . 'e' . $evento->getId();
    }

    /** O id é um dos nossos (`bjt<escritório>e<compromisso>`)? */
    public function ehIdNosso(string $googleId): bool
    {
        return 1 === preg_match('/^bjt\d+e\d+$/', $googleId);
    }

    /** O compromisso de um id nosso deste escritório; nulo se o id não é nosso ou é de outro escritório. */
    public function compromissoDoId(string $googleId, int $tenantId): ?int
    {
        return 1 === preg_match('/^bjt(\d+)e(\d+)$/', $googleId, $m) && (int) $m[1] === $tenantId ? (int) $m[2] : null;
    }

    /**
     * @param bool $copia vai à agenda de um participante (só leitura lá)
     *
     * @return array<string, mixed> o corpo do evento na API do Google
     */
    public function corpo(Evento $evento, bool $copia = false): array
    {
        $corpo = [
            'summary'     => $evento->getTitulo(),
            'description' => $this->descricao($evento, $copia),
            'location'    => (string) $evento->getLocal(),
            'status'      => Evento::STATUS_CANCELADO === $evento->getStatus() ? 'cancelled' : 'confirmed',
            'visibility'  => Evento::VISIBILIDADE_SOMENTE_EU === $evento->getVisibilidade() ? 'private' : 'default',
        ] + $this->quando($evento);
        $regra = $this->regra($evento);
        if (null !== $regra) {
            $corpo['recurrence'] = [$regra];
        }

        return $corpo;
    }

    /** Resumo do que vai ao Google: mudou o resumo, o compromisso vai de novo. */
    public function resumo(Evento $evento, bool $copia = false): string
    {
        return hash('sha256', (string) json_encode($this->corpo($evento, $copia)));
    }

    /**
     * Aplica ao compromisso o evento que veio do Google. Cancelado no Google vira situação "cancelado"
     * (o registro fica, decisão do coordenador). Nunca torna público o que é "Só eu".
     *
     * @param array<string, mixed> $item
     *
     * @return bool falso quando a repetição do Google não cabe na Agenda (o compromisso fica só do Google)
     */
    public function aplicar(array $item, Evento $evento): bool
    {
        if ('cancelled' === ($item['status'] ?? null)) {
            $evento->setStatus(Evento::STATUS_CANCELADO);

            return true;
        }
        if (Evento::STATUS_CANCELADO === $evento->getStatus()) {
            $evento->setStatus(Evento::STATUS_AGENDADO);
        }
        $evento->setTitulo(mb_substr(trim((string) ($item['summary'] ?? '')), 0, 255) ?: '(sem título)');
        $evento->setLocal($this->textoOuNulo($item['location'] ?? null, 255));
        $evento->setDescricao($this->textoOuNulo($item['description'] ?? null, 10000));
        if ('private' === ($item['visibility'] ?? null)) {
            $evento->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU);
        }
        $this->aplicarDatas($item, $evento);

        return $this->aplicarRepeticao($item, $evento);
    }

    /**
     * O item é o eco do nosso último envio (o Google não mudou nada desde então)? Aplica numa cópia e
     * compara o resumo com o guardado na ligação. Eco não sobrescreve: a pessoa pode ter editado aqui depois do
     * envio. Na cópia do participante (`copia`), o aviso de só leitura sai da descrição antes de aplicar.
     *
     * @param array<string, mixed> $item
     */
    public function ehEcoDoEnvio(array $item, Evento $evento, ?string $guardado, bool $copia = false): bool
    {
        if (null === $guardado || self::SO_DO_GOOGLE === $guardado) {
            return false;
        }
        if ($copia) {
            $item['description'] = $this->semAviso((string) ($item['description'] ?? ''), $evento);
        }
        $clone = clone $evento;
        // `aplicar` nunca torna público (proteção do "Só eu"); no clone, a visibilidade é a que o Google
        // mostra, senão uma troca para "Só eu" feita aqui depois do envio esconderia o eco.
        $clone->setVisibilidade('private' === ($item['visibility'] ?? null) ? Evento::VISIBILIDADE_SOMENTE_EU : Evento::VISIBILIDADE_TODOS);

        return $this->aplicar($item, $clone) && $this->resumo($clone, $copia) === $guardado;
    }

    /**
     * Evento que a pessoa não pode editar no Google (convite de outro organizador) ou que não é compromisso
     * (aniversário, local de trabalho, ausência): entra, se couber, mas não volta ao Google.
     *
     * @param array<string, mixed> $item
     */
    public function soDeLeitura(array $item): bool
    {
        $organizador = is_array($item['organizer'] ?? null) ? $item['organizer'] : [];

        return isset($item['organizer']) && true !== ($organizador['self'] ?? false);
    }

    /**
     * Tipos que não são compromisso (aniversário de contato, local de trabalho, ausência, foco) ficam de fora.
     *
     * @param array<string, mixed> $item
     */
    public function ehDeOutroTipo(array $item): bool
    {
        return isset($item['eventType']) && !in_array($item['eventType'], ['default', 'fromGmail'], true);
    }

    /**
     * E-mails dos convidados do evento (minúsculos), para achar quem é da equipe (modelo do dono, decisão a).
     * O de fora não é guardado: só serve para casar com a equipe nesta chamada.
     *
     * @param array<string, mixed> $item
     *
     * @return list<string>
     */
    public function emailsDosConvidados(array $item): array
    {
        $emails = [];
        foreach ((array) ($item['attendees'] ?? []) as $convidado) {
            $email = is_array($convidado) && is_string($convidado['email'] ?? null) ? mb_strtolower(trim($convidado['email'])) : '';
            if ('' !== $email && true !== ($convidado['self'] ?? false)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Ocorrência alterada de uma série do Google: não cabe no modelo de repetição da Agenda.
     *
     * @param array<string, mixed> $item
     */
    public function ehOcorrenciaDeSerie(array $item): bool
    {
        return isset($item['recurringEventId']);
    }

    private function semAviso(string $descricao, Evento $evento): string
    {
        $aviso = $this->avisoDaCopia($evento);

        return str_starts_with($descricao, $aviso) ? ltrim(substr($descricao, strlen($aviso))) : $descricao;
    }

    /** A cópia do participante diz de quem é e que lá é só leitura (o que mudarem lá volta como aqui). */
    private function descricao(Evento $evento, bool $copia): string
    {
        $descricao = (string) $evento->getDescricao();
        if (!$copia) {
            return $descricao;
        }

        return '' === $descricao ? $this->avisoDaCopia($evento) : $this->avisoDaCopia($evento) . "\n\n" . $descricao;
    }

    private function avisoDaCopia(Evento $evento): string
    {
        $dono = trim((string) $evento->getCriador()?->getFullName()) ?: 'um colega';

        return 'Compromisso de ' . $dono . ' no BlueJus. Para mudar, peça a quem criou: o que for mudado aqui volta como está no BlueJus.';
    }

    /** @return array{start: array<string, string>, end: array<string, string>} */
    private function quando(Evento $evento): array
    {
        $inicio = $evento->getDataInicio() ?? new \DateTimeImmutable();
        $fim = $evento->getDataFim() ?? $inicio;
        if ($evento->isDiaInteiro()) {
            return ['start' => ['date' => $inicio->format('Y-m-d')], 'end' => ['date' => $fim->modify('+1 day')->format('Y-m-d')]];
        }

        return [
            'start' => ['dateTime' => $inicio->format('Y-m-d\TH:i:s'), 'timeZone' => self::FUSO],
            'end'   => ['dateTime' => $fim->format('Y-m-d\TH:i:s'), 'timeZone' => self::FUSO],
        ];
    }

    private function regra(Evento $evento): ?string
    {
        $freq = self::FREQUENCIAS[(string) $evento->getTipoRecorrencia()] ?? null;
        if (!$evento->isRecorrente() || null === $freq) {
            return null;
        }
        $fim = $evento->getFimRecorrencia();
        if (null === $fim) {
            return 'RRULE:FREQ=' . $freq;
        }
        // RFC 5545: em dia inteiro, UNTIL é data; com hora, é UTC. O último dia vai até 23:59:59 de Brasília.
        $ate = $evento->isDiaInteiro()
            ? $fim->format('Ymd')
            : (new \DateTimeImmutable($fim->format('Y-m-d') . ' 23:59:59', new \DateTimeZone(self::FUSO)))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

        return 'RRULE:FREQ=' . $freq . ';UNTIL=' . $ate;
    }

    /** @param array<string, mixed> $item */
    private function aplicarDatas(array $item, Evento $evento): void
    {
        $inicio = is_array($item['start'] ?? null) ? $item['start'] : [];
        $fim = is_array($item['end'] ?? null) ? $item['end'] : $inicio;
        if (isset($inicio['date'])) {
            $dia = new \DateTimeImmutable((string) $inicio['date'] . ' 00:00:00');
            $ultimo = isset($fim['date']) ? (new \DateTimeImmutable((string) $fim['date'] . ' 23:59:59'))->modify('-1 day') : $dia->setTime(23, 59, 59);
            $evento->setDiaInteiro(true)->setDataInicio($dia)->setDataFim($ultimo < $dia ? $dia->setTime(23, 59, 59) : $ultimo);

            return;
        }
        $comeco = $this->paraBrasilia((string) ($inicio['dateTime'] ?? 'now'));
        $termino = isset($fim['dateTime']) ? $this->paraBrasilia((string) $fim['dateTime']) : $comeco->modify('+1 hour');
        $evento->setDiaInteiro(false)->setDataInicio($comeco)->setDataFim($termino < $comeco ? $comeco : $termino);
    }

    /** @param array<string, mixed> $item */
    private function aplicarRepeticao(array $item, Evento $evento): bool
    {
        $regras = array_values(array_filter((array) ($item['recurrence'] ?? []), static fn ($r): bool => is_string($r) && str_starts_with($r, 'RRULE:')));
        if ([] === $regras) {
            $evento->setRecorrente(false)->setTipoRecorrencia(null)->setFimRecorrencia(null);

            return true;
        }
        $partes = $this->partes(substr($regras[0], 6));
        $tipo = array_search($partes['FREQ'] ?? '', self::FREQUENCIAS, true);
        if (false === $tipo || !$this->cabeNaAgenda($partes, $evento->getDataInicio() ?? new \DateTimeImmutable())) {
            $evento->setRecorrente(false)->setTipoRecorrencia(null)->setFimRecorrencia(null);
            $evento->setDescricao(trim(($evento->getDescricao() ?? '') . "\n\n" . self::REPETICAO_NAO_SUPORTADA));

            return false;
        }
        $evento->setRecorrente(true)->setTipoRecorrencia((string) $tipo)->setFimRecorrencia(isset($partes['UNTIL']) ? $this->ate($partes['UNTIL']) : null);

        return true;
    }

    /**
     * A Agenda repete no mesmo dia do início: BYDAY só em semanal e no dia da semana do início; BYMONTHDAY
     * só em mensal e no dia do início; BYMONTH só em anual e no mês do início. Nada de INTERVAL, COUNT etc.
     *
     * @param array<string, string> $partes
     */
    private function cabeNaAgenda(array $partes, \DateTimeImmutable $inicio): bool
    {
        if ([] !== array_diff(array_keys($partes), self::CHAVES_ACEITAS)) {
            return false;
        }
        $freq = $partes['FREQ'];

        return (!isset($partes['BYDAY']) || ('WEEKLY' === $freq && self::DIAS[(int) $inicio->format('w')] === $partes['BYDAY']))
            && (!isset($partes['BYMONTHDAY']) || ('MONTHLY' === $freq && (int) $partes['BYMONTHDAY'] === (int) $inicio->format('j')))
            && (!isset($partes['BYMONTH']) || ('YEARLY' === $freq && (int) $partes['BYMONTH'] === (int) $inicio->format('n')));
    }

    /** UNTIL em data (dia inteiro) ou em UTC (com hora): o último dia, na data de Brasília. */
    private function ate(string $until): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{8})(T\d{6}Z)?$/', $until, $m)) {
            return null;
        }
        $data = isset($m[2])
            ? (new \DateTimeImmutable($until, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::FUSO))->format('Y-m-d')
            : (\DateTimeImmutable::createFromFormat('!Ymd', $m[1]) ?: new \DateTimeImmutable())->format('Y-m-d');

        return new \DateTimeImmutable($data . ' 23:59:59');
    }

    /** @return array<string, string> */
    private function partes(string $regra): array
    {
        $partes = [];
        foreach (explode(';', $regra) as $par) {
            [$chave, $valor] = array_pad(explode('=', $par, 2), 2, '');
            $partes[strtoupper(trim($chave))] = trim($valor);
        }

        return $partes;
    }

    private function paraBrasilia(string $dataHora): \DateTimeImmutable
    {
        $local = (new \DateTimeImmutable($dataHora))->setTimezone(new \DateTimeZone(self::FUSO));

        return new \DateTimeImmutable($local->format('Y-m-d H:i:s'));
    }

    private function textoOuNulo(mixed $texto, int $maximo): ?string
    {
        $texto = is_string($texto) ? trim($texto) : '';

        return '' === $texto ? null : mb_substr($texto, 0, $maximo);
    }
}
