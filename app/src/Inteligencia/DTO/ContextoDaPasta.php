<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;

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
     * @param list<string>           $abasOcultas         abas da pasta ocultas para quem pediu (seções fora)
     * @param list<int>|null         $documentosSelecionados ids marcados na aba Documentos (F4); nulo = sem recorte
     */
    public function __construct(
        public Agente $agente,
        public array $cabecalho,
        public array $processos,
        public array $secoes,
        public string $hash,
        public bool $incluiFinanceiro,
        public array $numerosDosProcessos = [],
        public array $abasOcultas = [],
        /** Comando ou relatório do drawer que pediu a análise; nulo no pedido por agente (rotas antigas). */
        public ?Comando $comando = null,
        /** Os documentos marcados na aba Documentos do drawer (F4), em ordem; nulo quando não há recorte. */
        public ?array $documentosSelecionados = null,
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
     *
     * O comando entra no hash quando há: dois comandos do mesmo agente leem os mesmos dados, mas
     * cada um tem a sua trilha de "nada novo". A seleção de documentos (F4) também: "nada novo" vale
     * por seleção.
     *
     * @param list<int>|null $documentosSelecionados
     */
    public static function hashDe(Agente $agente, array $cabecalho, array $processos, array $secoes, ?Comando $comando = null, ?array $documentosSelecionados = null): string
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
        // Só com comando: o hash do pedido por agente continua o mesmo de antes (o "nada novo" das
        // análises antigas segue valendo).
        if ($comando !== null) {
            $base['comando'] = $comando->value;
        }
        if ($documentosSelecionados !== null) {
            $base['documentosSelecionados'] = $documentosSelecionados;
        }

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
     * `AnaliseOutput` (base da análise); `financeiro`, `abasOcultas` e `documentosSelecionados` são
     * decisões tomadas na solicitação, que o worker obedece.
     *
     * @return array{agente: string, secoes: array<string, int>, omitidas: array<string, int>, total: int, processos: list<string>, financeiro: bool, abasOcultas: list<string>, comando?: string, documentosSelecionados?: list<int>}
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

        $resumo = [
            'agente' => $this->agente->value,
            'secoes' => $secoes,
            'omitidas' => $omitidas,
            'total' => $this->totalDeItens(),
            'processos' => $this->numerosDosProcessos,
            'financeiro' => $this->incluiFinanceiro,
            'abasOcultas' => $this->abasOcultas,
        ];
        if ($this->comando !== null) {
            $resumo['comando'] = $this->comando->value;
        }
        if ($this->documentosSelecionados !== null) {
            $resumo['documentosSelecionados'] = $this->documentosSelecionados;
        }

        return $resumo;
    }
}
