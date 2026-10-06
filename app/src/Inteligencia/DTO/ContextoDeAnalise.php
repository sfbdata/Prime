<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O contexto montado para UMA análise: cabeçalho do caso + movimentações (mais recente primeiro) +
 * hash. O hash cobre só chave e texto das movimentações — é a resposta para "há algo novo desde a
 * última análise?"; responsável/equipe mudando não é movimentação nova.
 *
 * O texto integral NUNCA é persistido (D5): o que vai para `contexto_resumo` é {@see resumo()} —
 * ids, chaves e contagens. O prompt é reconstituível a partir das publicações, que já estão no banco.
 */
final readonly class ContextoDeAnalise
{
    /**
     * @param array<string, string>        $cabecalho processo, classe, assunto, tribunal, orgao, responsavel, equipe, pasta
     * @param list<MovimentacaoDeContexto> $itens
     * @param list<string>                 $numerosDosProcessos
     */
    public function __construct(
        public array $cabecalho,
        public array $itens,
        public string $hash,
        public array $numerosDosProcessos = [],
    ) {
    }

    /** @param list<MovimentacaoDeContexto> $itens */
    public static function hashDe(array $itens): string
    {
        $base = array_map(
            static fn (MovimentacaoDeContexto $item): array => [$item->chave, $item->texto],
            $itens,
        );

        return hash('sha256', (string) json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function vazio(): bool
    {
        return $this->itens === [];
    }

    /** @return list<string> */
    public function chaves(): array
    {
        return array_map(static fn (MovimentacaoDeContexto $item): string => $item->chave, $this->itens);
    }

    /**
     * O que fica gravado em `contexto_resumo`: ids e contagens, nunca o texto.
     *
     * @param list<string> $chavesJaAnalisadas chaves da análise concluída anterior
     * @return array{chaves: list<string>, publicacoes: list<int>, movimentacoes: list<int>, total: int, novas: int, processos: list<string>}
     */
    public function resumo(array $chavesJaAnalisadas = []): array
    {
        $publicacoes = [];
        $movimentacoes = [];
        foreach ($this->itens as $item) {
            if ($item->ehPublicacao()) {
                $publicacoes[] = $item->id();
                continue;
            }
            $movimentacoes[] = $item->id();
        }

        $chaves = $this->chaves();

        return [
            'chaves' => $chaves,
            'publicacoes' => $publicacoes,
            'movimentacoes' => $movimentacoes,
            'total' => count($chaves),
            'novas' => count(array_diff($chaves, $chavesJaAnalisadas)),
            'processos' => $this->numerosDosProcessos,
        ];
    }
}
