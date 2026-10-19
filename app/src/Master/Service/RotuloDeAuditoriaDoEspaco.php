<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;

/**
 * Rótulo e detalhe da trilha "Segurança e auditoria" para as entidades da Contribua (spec
 * perfis-1.2-planos-e-limites P7; desenho 05 - Perfis 1.2, linha 732):
 *  - limite da conta: "Limite de armazenamento" ou "Limite de usuários", pelo campo que mudou no diff,
 *    com "anterior → novo · justificativa";
 *  - catálogo: "Plano alterado", com cada campo que mudou;
 *  - configuração: "Faixas de alerta alteradas".
 *
 * Diferente do resto da trilha, aqui o detalhe leva os VALORES: limites, preços e faixas não são dado
 * sensível, e o desenho pede "15 GB → 20 GB · cliente contratou ampliação". Só lê o `changes` do
 * `audit_log`; nunca consulta a tabela do espaço.
 */
final class RotuloDeAuditoriaDoEspaco
{
    private const DO_PLANO = 'limite do plano';
    private const SEM_LIMITE = 'sem limite';

    private const FAIXAS = ['faixaAtencao' => 'Atenção', 'faixaAlerta' => 'Alerta', 'faixaCritico' => 'Crítico', 'faixaBloqueio' => 'Bloqueio'];

    /**
     * @param array<string, mixed> $mudancas o `changes` decodificado
     *
     * @return array{acao: string, detalhe: string}|null nulo quando a entidade não é da Contribua
     */
    public static function traduzir(string $acao, string $entidade, array $mudancas): ?array
    {
        return match ($entidade) {
            EspacoDoEscritorio::class => self::limite($acao, $mudancas),
            Plano::class => ['acao' => ['create' => 'Plano criado', 'delete' => 'Plano excluído'][$acao] ?? 'Plano alterado', 'detalhe' => self::detalheDoPlano(self::diff($mudancas))],
            ConfiguracaoDoEspaco::class => ['acao' => 'Faixas de alerta alteradas', 'detalhe' => self::detalheDasFaixas(self::diff($mudancas))],
            default => null,
        };
    }

    /**
     * Na criação (primeira mudança de limite da conta) não há "anterior" gravado: era o limite do plano.
     *
     * @param array<string, mixed> $mudancas
     *
     * @return array{acao: string, detalhe: string}
     */
    private static function limite(string $acao, array $mudancas): array
    {
        if ($acao === 'create') {
            $depois = is_array($mudancas['diff']['after'] ?? null) ? $mudancas['diff']['after'] : [];
            $diff = [
                'cotaGb' => ['from' => null, 'to' => $depois['cotaGb'] ?? null],
                'semLimite' => ['from' => false, 'to' => $depois['semLimite'] ?? false],
                'justificativa' => ['to' => $depois['justificativa'] ?? null],
            ];
            if (($depois['limiteUsuarios'] ?? null) !== null) {
                $diff = ['limiteUsuarios' => ['from' => null, 'to' => $depois['limiteUsuarios']], 'justificativa' => $diff['justificativa']];
            }

            return self::limiteAlterado($diff);
        }

        return self::limiteAlterado(self::diff($mudancas));
    }

    /**
     * @param array<string, mixed> $diff
     *
     * @return array{acao: string, detalhe: string}
     */
    private static function limiteAlterado(array $diff): array
    {
        $justificativa = is_string($diff['justificativa']['to'] ?? null) ? ' · ' . $diff['justificativa']['to'] : '';

        if (isset($diff['limiteUsuarios'])) {
            $usuarios = static fn (mixed $v): string => is_int($v) ? $v . ' usuários' : self::DO_PLANO;

            return ['acao' => 'Limite de usuários', 'detalhe' => $usuarios($diff['limiteUsuarios']['from'] ?? null) . ' → ' . $usuarios($diff['limiteUsuarios']['to'] ?? null) . $justificativa];
        }

        if (isset($diff['cotaGb']) || isset($diff['semLimite'])) {
            return ['acao' => 'Limite de armazenamento', 'detalhe' => self::armazenamento($diff, 'from') . ' → ' . self::armazenamento($diff, 'to') . $justificativa];
        }

        if (isset($diff['plano'])) {
            return ['acao' => 'Plano da conta alterado', 'detalhe' => 'Mudança de plano' . $justificativa];
        }

        // Mesmo valor de antes com justificativa nova (o desenho permite "Diferença: +0").
        return ['acao' => 'Limite mantido', 'detalhe' => 'valor sem mudança' . $justificativa];
    }

    /**
     * Um lado do limite de armazenamento. Campo fora do diff não mudou; como `semLimite` sempre zera a
     * cota, a cota fora do diff só pode ser nula, e o `semLimite` fora do diff só pode ser falso.
     *
     * @param array<string, mixed> $diff
     */
    private static function armazenamento(array $diff, string $lado): string
    {
        if (($diff['semLimite'][$lado] ?? false) === true) {
            return self::SEM_LIMITE;
        }
        $cota = $diff['cotaGb'][$lado] ?? null;

        return is_int($cota) ? $cota . ' GB' : self::DO_PLANO;
    }

    /** @param array<string, mixed> $diff */
    private static function detalheDoPlano(array $diff): string
    {
        $formatos = [
            'nome' => ['nome', static fn (mixed $v): string => (string) $v],
            'cotaGb' => ['espaço', static fn (mixed $v): string => $v . ' GB'],
            'precoCentavos' => ['preço', static fn (mixed $v): string => self::reais((int) $v)],
            'limiteUsuarios' => ['usuários', static fn (mixed $v): string => $v === null ? self::SEM_LIMITE : (string) $v],
            'descricao' => ['descrição', static fn (mixed $v): string => (string) $v],
            'ativo' => ['situação', static fn (mixed $v): string => $v === true ? 'ativo' : 'inativo'],
        ];

        $partes = [];
        foreach ($formatos as $campo => [$rotulo, $formatar]) {
            if (isset($diff[$campo]) && is_array($diff[$campo])) {
                $partes[] = $rotulo . ': ' . $formatar($diff[$campo]['from'] ?? null) . ' → ' . $formatar($diff[$campo]['to'] ?? null);
            }
        }

        return $partes === [] ? 'Alteração sem detalhe registrado' : implode('; ', $partes);
    }

    /** @param array<string, mixed> $diff */
    private static function detalheDasFaixas(array $diff): string
    {
        $partes = [];
        foreach (self::FAIXAS as $campo => $rotulo) {
            if (isset($diff[$campo]) && is_array($diff[$campo])) {
                $partes[] = sprintf('%s: %s%% → %s%%', $rotulo, (string) ($diff[$campo]['from'] ?? ''), (string) ($diff[$campo]['to'] ?? ''));
            }
        }
        if (isset($diff['bloqueioAtivo']) && is_array($diff['bloqueioAtivo'])) {
            $chave = static fn (mixed $v): string => $v === true ? 'ligado' : 'desligado';
            $partes[] = 'bloqueio de envio: ' . $chave($diff['bloqueioAtivo']['from'] ?? null) . ' → ' . $chave($diff['bloqueioAtivo']['to'] ?? null);
        }

        return $partes === [] ? 'Alteração sem detalhe registrado' : implode('; ', $partes);
    }

    /** "R$ 0", "R$ 189", "R$ 19,90": o formato dos cartões de plano. */
    public static function reais(int $centavos): string
    {
        $casas = $centavos % 100 === 0 ? 0 : 2;

        return 'R$ ' . number_format($centavos / 100, $casas, ',', '.');
    }

    /**
     * @param array<string, mixed> $mudancas
     *
     * @return array<string, mixed>
     */
    private static function diff(array $mudancas): array
    {
        $diff = $mudancas['diff']['changes'] ?? null;

        return is_array($diff) ? $diff : [];
    }
}
