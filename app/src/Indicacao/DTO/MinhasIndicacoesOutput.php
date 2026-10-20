<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

/**
 * O que a janela "Indicar o BlueJus" mostra (bj-contas.js L98-133): o nome que o convite leva, o link curto, a
 * mensagem sugerida do WhatsApp, o que falta no cadastro (ou a trava vigente) e as 8 indicações mais recentes.
 */
final class MinhasIndicacoesOutput
{
    /**
     * @param list<string>                   $pendencias
     * @param list<IndicacaoDoUsuarioOutput> $ultimas
     */
    public function __construct(
        public readonly string $nome,
        public readonly string $linkCurto,
        public readonly string $mensagem,
        public readonly array $pendencias,
        public readonly ?string $textoDasPendencias,
        public readonly ?string $trava,
        public readonly int $total,
        public readonly array $ultimas,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'nome' => $this->nome, 'link' => $this->linkCurto, 'mensagem' => $this->mensagem,
            'pendencias' => $this->pendencias, 'textoDasPendencias' => $this->textoDasPendencias, 'trava' => $this->trava,
            'total' => $this->total,
            'ultimas' => array_map(static fn (IndicacaoDoUsuarioOutput $i): array => $i->paraJson(), $this->ultimas),
        ];
    }
}
