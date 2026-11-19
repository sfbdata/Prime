<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Agenda\DTO\CompromissoInput;
use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;

/**
 * As contas de data e de campo da `gravar_na_agenda` (PR 13): junta o que a pessoa mandou com o compromisso que já
 * existe (campo omitido na edição fica como está), monta o {@see CompromissoInput} da tela e guarda o estado do
 * compromisso que a proposta mostra, para o `reconferir` saber se mudou.
 */
final class GravarNaAgendaCompromisso
{
    private function __construct()
    {
    }

    /**
     * Os valores finais do compromisso: os atuais (ou o padrão, ao criar) com o que veio por cima. Sem `fim`, a edição
     * mantém a duração de antes; ao criar sem horário, é dia inteiro.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    public static function consolidar(?Evento $atual, array $campos): array
    {
        $base = $atual === null ? self::padrao() : self::doEvento($atual);
        if (!\array_key_exists('dia_inteiro', $campos) && ($atual === null || isset($campos['inicio']))) {
            // Mandou a hora: é compromisso com horário. Ao criar sem hora, é dia inteiro.
            $campos['dia_inteiro'] = !isset($campos['inicio']);
        }
        unset($campos['compromisso_id']);
        $final = array_replace($base, $campos);
        [$final['fim'], $final['dias']] = self::fimEDias($atual, $campos, $base, $final);

        return $final;
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, mixed> $base
     * @param array<string, mixed> $final
     *
     * @return array{0: string, 1: int}
     */
    private static function fimEDias(?Evento $atual, array $campos, array $base, array $final): array
    {
        if (\array_key_exists('fim', $campos)) {
            return [(string) $campos['fim'], (int) ($campos['dias'] ?? 0)];
        }
        if ($atual === null || $final['dia_inteiro'] === true) {
            return ['', (int) $final['dias']];
        }
        if (\array_key_exists('dias', $campos)) {
            return [(string) $base['fim'], (int) $campos['dias']];
        }
        $duracao = $atual->isDiaInteiro() ? 3600 : max(60, self::fim($atual)->getTimestamp() - self::inicio($atual)->getTimestamp());
        $novoFim = (new \DateTimeImmutable($final['data'] . ' ' . $final['inicio']))->modify('+' . $duracao . ' seconds');

        return [$novoFim->format('H:i'), self::diasEntre((string) $final['data'], $novoFim->format('Y-m-d'))];
    }

    /** @return array<string, mixed> */
    private static function padrao(): array
    {
        return [
            'titulo' => '', 'tipo' => 'meus', 'data' => '', 'dia_inteiro' => true, 'inicio' => '00:00', 'fim' => '', 'dias' => 0,
            'visibilidade' => 'equipe', 'pasta_id' => null, 'participantes' => [], 'lembrete_minutos' => 15, 'observacao' => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function doEvento(Evento $e): array
    {
        $inicio = self::inicio($e);
        $fim = self::fim($e);

        return [
            'titulo' => $e->getTitulo(),
            'tipo' => $e->getTipo() === null ? 'meus' : $e->getTipo()->value,
            'data' => $inicio->format('Y-m-d'),
            'dia_inteiro' => $e->isDiaInteiro(),
            'inicio' => $e->isDiaInteiro() ? '00:00' : $inicio->format('H:i'),
            'fim' => $e->isDiaInteiro() ? '' : $fim->format('H:i'),
            'dias' => self::diasEntre($inicio->format('Y-m-d'), $fim->format('Y-m-d')),
            'visibilidade' => $e->getVisibilidade() === Evento::VISIBILIDADE_SOMENTE_EU ? 'so_eu' : 'equipe',
            'pasta_id' => $e->getPasta()?->getId(),
            'participantes' => array_values(array_map(static fn (User $u): int => (int) $u->getId(), $e->getParticipantes()->toArray())),
            'lembrete_minutos' => $e->getLembreteMinutos() ?? 15,
            'observacao' => $e->getDescricao() ?? '',
        ];
    }

    /**
     * O que a tela manda ao UseCase. A repetição de quem já se repete continua como está (esta ferramenta não a muda).
     *
     * @param array<string, mixed> $final
     */
    public static function input(array $final, string $nupDaPasta, ?Evento $atual): CompromissoInput
    {
        $repete = $atual !== null && $atual->isRecorrente();

        return new CompromissoInput(
            titulo: (string) $final['titulo'],
            tipo: (string) $final['tipo'],
            data: (string) $final['data'],
            diaInteiro: (bool) $final['dia_inteiro'],
            inicio: (string) $final['inicio'],
            fim: (string) $final['fim'],
            visibilidade: $final['visibilidade'] === 'so_eu' ? 'privado' : 'equipe',
            pasta: $nupDaPasta,
            lembreteMinutos: (int) $final['lembrete_minutos'],
            observacao: (string) $final['observacao'],
            repetir: $repete ? (string) $atual->getTipoRecorrencia() : '',
            repetirAte: $repete ? $atual->getFimRecorrencia()?->format('Y-m-d') : null,
            participantes: array_values(array_map('intval', (array) $final['participantes'])),
            dias: (int) $final['dias'],
        );
    }

    /**
     * Tudo o que a proposta pode sobrescrever ou mostrar do compromisso, mais o `modificado_em`: se qualquer um mudar até
     * a confirmação, a proposta já não vale.
     *
     * @return array<string, mixed>
     */
    public static function estado(Evento $e): array
    {
        $valores = self::doEvento($e);
        sort($valores['participantes']);

        return $valores + [
            'inicio_em' => self::inicio($e)->format('Y-m-d H:i'),
            'fim_em' => self::fim($e)->format('Y-m-d H:i'),
            'lembrete_bruto' => $e->getLembreteMinutos(),
            'modificado_em' => $e->getModificadoEm()?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    /** "15/12/2026 14:00", ou só a data no dia inteiro. */
    public static function quando(\DateTimeInterface $inicio, bool $diaInteiro): string
    {
        return $inicio->format($diaInteiro ? 'd/m/Y' : 'd/m/Y H:i') . ($diaInteiro ? ' (dia inteiro)' : '');
    }

    public static function quandoDoEvento(Evento $e): string
    {
        return self::quando(self::inicio($e), $e->isDiaInteiro());
    }

    /** @param array<string, mixed> $final */
    public static function quandoDoFinal(array $final): string
    {
        $diaInteiro = (bool) $final['dia_inteiro'];

        return self::quando(new \DateTimeImmutable($final['data'] . ' ' . ($diaInteiro ? '00:00' : $final['inicio'])), $diaInteiro);
    }

    private static function inicio(Evento $e): \DateTimeImmutable
    {
        return $e->getDataInicio() ?? new \DateTimeImmutable();
    }

    private static function fim(Evento $e): \DateTimeImmutable
    {
        return $e->getDataFim() ?? self::inicio($e);
    }

    private static function diasEntre(string $de, string $ate): int
    {
        return (int) (new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->days;
    }
}
