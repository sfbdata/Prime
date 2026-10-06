<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Agente;

/**
 * O contexto montado para UMA análise de agente: cabeçalho da pasta, processos vinculados e as
 * seções que o agente lê (já preparadas), mais o hash de tudo isso — a resposta para "há algo novo
 * desde a última análise deste agente?".
 *
 * O texto integral NUNCA é persistido (D5): o que vai para `contexto_resumo` é {@see resumo()} —
 * contagens por seção, cortes, processos e a decisão sobre o financeiro.
 */
final readonly class ContextoDaPasta
{
    /**
     * @param array<string, string>  $cabecalho           pasta, situacao, prioridade, acao, abertura, responsavel, equipe
     * @param list<string>           $processos           uma linha por processo vinculado
     * @param list<SecaoDeContexto>  $secoes              na ordem do agente
     * @param list<string>           $numerosDosProcessos
     */
    public function __construct(
        public Agente $agente,
        public array $cabecalho,
        public array $processos,
        public array $secoes,
        public string $hash,
        public bool $incluiFinanceiro,
        public array $numerosDosProcessos = [],
    ) {
    }

    /** Campos do cabeçalho que NÃO entram no hash: a equipe do escritório mudar não é dado novo da pasta. */
    private const CABECALHO_FORA_DO_HASH = ['equipe'];

    /**
     * Hash sobre a forma ESTÁVEL do contexto: cabeçalho sem a equipe, linhas dos processos e as
     * assinaturas das seções (ids, datas absolutas, estado) — nunca o texto relativo do prompt
     * ("vence em N dia(s)"), que mudaria todo dia e faria "nada novo" valer só no mesmo dia.
     *
     * @param array<string, string> $cabecalho
     * @param list<string>          $processos
     * @param list<SecaoDeContexto> $secoes
     */
    public static function hashDe(Agente $agente, array $cabecalho, array $processos, array $secoes): string
    {
        $base = [
            'agente' => $agente->value,
            'cabecalho' => array_diff_key($cabecalho, array_flip(self::CABECALHO_FORA_DO_HASH)),
            'processos' => $processos,
            'secoes' => array_map(
                static fn (SecaoDeContexto $s): array => [$s->secao->value, $s->assinaturasParaHash(), $s->omitidas],
                $secoes,
            ),
        ];

        return hash('sha256', (string) json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Nenhuma seção com linha: só o cabeçalho não é análise. */
    public function vazio(): bool
    {
        foreach ($this->secoes as $secao) {
            if (!$secao->vazia()) {
                return false;
            }
        }

        return true;
    }

    public function totalDeItens(): int
    {
        $total = 0;
        foreach ($this->secoes as $secao) {
            $total += $secao->total();
        }

        return $total;
    }

    /**
     * O que fica gravado em `contexto_resumo`: contagens, nunca o texto. `total` é lido pelo
     * `AnaliseOutput` (base da análise); `financeiro` é a decisão tomada na solicitação, que o
     * worker obedece.
     *
     * @return array{agente: string, secoes: array<string, int>, omitidas: array<string, int>, total: int, processos: list<string>, financeiro: bool}
     */
    public function resumo(): array
    {
        $secoes = [];
        $omitidas = [];
        foreach ($this->secoes as $secao) {
            $secoes[$secao->secao->value] = $secao->total();
            if ($secao->omitidas > 0) {
                $omitidas[$secao->secao->value] = $secao->omitidas;
            }
        }

        return [
            'agente' => $this->agente->value,
            'secoes' => $secoes,
            'omitidas' => $omitidas,
            'total' => $this->totalDeItens(),
            'processos' => $this->numerosDosProcessos,
            'financeiro' => $this->incluiFinanceiro,
        ];
    }
}
