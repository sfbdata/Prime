<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Entity\Tenant\Tenant;

/**
 * O resumo da proposta de edição da `gravar_na_agenda` (PR 13): cita CADA campo que a edição muda, do valor de agora para
 * o novo, para a pessoa aprovar sabendo tudo o que será sobrescrito. Nomes e textos do banco saem pela
 * {@see ProtecaoDoTextoDoChat}.
 */
final class GravarNaAgendaResumo
{
    private const TIPOS = ['meus' => 'compromisso', 'aud' => 'audiência', 'reun' => 'reunião', 'prazo' => 'prazo', 'urg' => 'urgente', 'pess' => 'pessoal', 'lemb' => 'lembrete'];
    private const TAMANHO_DA_OBSERVACAO = 300;

    public function __construct(
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly Tenant $escritorio,
    ) {
    }

    /**
     * @param array<string, mixed>  $antes     os valores de agora ({@see GravarNaAgendaCompromisso::doEvento()})
     * @param array<string, mixed>  $depois    os valores finais
     * @param array<int, string>    $nomes     id da pessoa => nome (quem pode entrar ou sair)
     * @param array<int, string>    $pastas    id da pasta => número
     * @param list<int>             $ativos    os ids da equipe ativa: só esses saem (quem já saiu da equipe continua no compromisso)
     */
    public function edicao(array $antes, array $depois, array $nomes, array $pastas, array $ativos): string
    {
        $mudancas = array_filter([
            $this->par('título', $antes['titulo'], $depois['titulo'], fn (mixed $v): string => $this->texto((string) $v)),
            $this->par('tipo', $antes['tipo'], $depois['tipo'], static fn (mixed $v): string => self::TIPOS[$v] ?? (string) $v),
            $this->par('quando', self::quando($antes), self::quando($depois), static fn (mixed $v): string => (string) $v),
            $this->par('visibilidade', $antes['visibilidade'], $depois['visibilidade'], static fn (mixed $v): string => $v === 'so_eu' ? 'só para você' : 'para a equipe'),
            $this->par('pasta', $antes['pasta_id'], $depois['pasta_id'], fn (mixed $v): string => $v === null ? 'sem pasta' : $this->texto($pastas[$v] ?? 'sem número')),
            $this->par('lembrete', $antes['lembrete_minutos'], $depois['lembrete_minutos'], static fn (mixed $v): string => $v === 0 ? 'sem lembrete' : $v . ' min antes'),
            $this->par('observação', $antes['observacao'], $depois['observacao'], fn (mixed $v): string => $this->observacao((string) $v)),
            $this->participantes($antes['participantes'], $depois['participantes'], $nomes, $ativos),
        ]);

        return $mudancas === [] ? 'Salvar o compromisso sem mudar nenhum campo.' : 'Alterar o compromisso. Muda: ' . implode('; ', $mudancas) . '.';
    }

    /** @param callable(mixed): string $mostrar */
    private function par(string $campo, mixed $antes, mixed $depois, callable $mostrar): ?string
    {
        return $antes === $depois ? null : sprintf('%s de %s para %s', $campo, $mostrar($antes), $mostrar($depois));
    }

    /**
     * @param list<int>          $antes
     * @param list<int>          $depois
     * @param array<int, string> $nomes
     * @param list<int>          $ativos
     */
    private function participantes(array $antes, array $depois, array $nomes, array $ativos): ?string
    {
        $entram = array_values(array_diff($depois, $antes));
        $saem = array_values(array_filter(array_diff($antes, $depois), static fn (int $id): bool => \in_array($id, $ativos, true)));
        if ($entram === [] && $saem === []) {
            return null;
        }
        $partes = [];
        foreach (['entram' => $entram, 'saem' => $saem] as $verbo => $ids) {
            if ($ids !== []) {
                $partes[] = $verbo . ' ' . implode(', ', array_map(fn (int $id): string => $this->texto($nomes[$id] ?? 'pessoa'), $ids));
            }
        }

        return 'participantes: ' . implode('; ', $partes);
    }

    private function texto(string $texto): string
    {
        return '"' . $this->protecao->rotulo($this->escritorio, $texto, 'sem texto') . '"';
    }

    private function observacao(string $texto): string
    {
        if (trim($texto) === '') {
            return 'vazia';
        }
        $curto = mb_strlen($texto) > self::TAMANHO_DA_OBSERVACAO ? mb_substr($texto, 0, self::TAMANHO_DA_OBSERVACAO) . '…' : $texto;

        return '"' . trim(preg_replace('/\s+/', ' ', $this->protecao->comoDado($this->escritorio, $curto)) ?? '') . '"';
    }

    /** @param array<string, mixed> $v */
    private static function quando(array $v): string
    {
        $dia = (new \DateTimeImmutable((string) $v['data']))->format('d/m/Y');
        $depois = (int) $v['dias'] > 0 ? ', termina ' . $v['dias'] . ' dia(s) depois' : '';
        if ($v['dia_inteiro'] === true) {
            return $dia . ' (dia inteiro)' . $depois;
        }

        return $dia . ' ' . $v['inicio'] . ($v['fim'] === '' ? '' : ' às ' . $v['fim']) . $depois;
    }
}
