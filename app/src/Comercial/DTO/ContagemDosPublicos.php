<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O que a coluna "2 · PARA QUEM" e a linha de destinatários mostram (dc L.237, L.252, L.539): o número de cada público
 * na grade (o da fonte, como o dc), e, do público escolhido, quantos recebem, quantos descadastrados e quantos sem
 * autorização saíram, e o primeiro destinatário (o exemplo da prévia, dc L.537).
 *
 * `amostra` são os primeiros destinatários (até {@see self::TAMANHO_DA_AMOSTRA}), que a confirmação do "Enviar" mostra
 * (D1, 27/11/2026). `importacao` é o relatório do CSV importado (A2), só no público `csv` com arquivo.
 */
final class ContagemDosPublicos
{
    public const TAMANHO_DA_AMOSTRA = 3;

    /**
     * @param array<string, int>           $publicos
     * @param list<DestinatarioDaCampanha> $amostra
     */
    public function __construct(
        public readonly array $publicos,
        public readonly int $destinatarios,
        public readonly int $descadastrados,
        public readonly int $semAutorizacao,
        public readonly ?DestinatarioDaCampanha $exemplo,
        public readonly int $quantosFiltros = 0,
        public readonly ?int $base = null,
        public readonly int $semInformacao = 0,
        public readonly int $repetidos = 0,
        public readonly int $invalidos = 0,
        public readonly int $puladosPeloIntervalo = 0,
        public readonly array $amostra = [],
        public readonly ?RelatorioDaImportacao $importacao = null,
    ) {
    }

    /**
     * O `filtro` é o que a tela do dc escreve (L662-663): "N de M atendem ao filtro · K sem essa informação no
     * cadastro (ficam de fora)", "Enviando para todos da lista (M)", e o selo com quantos campos estão preenchidos.
     *
     * @return array{publicos: array<string, int>, destinatarios: int, descadastrados: int, semAutorizacao: int, repetidos: int, invalidos: int, puladosPeloIntervalo: int, exemplo: array{email: string, nome: string}|null, amostra: list<array{email: string, nome: string}>, importacao: array{lidas: int, validas: int, repetidas: int, invalidas: int, vazias: int, recusadas: list<array{linha: int, conteudo: string, motivo: string}>}|null, filtro: array{ativo: bool, quantos: int, base: int, atendem: int, semInformacao: int}}
     */
    public function paraArray(): array
    {
        return [
            'publicos' => $this->publicos, 'destinatarios' => $this->destinatarios, 'descadastrados' => $this->descadastrados,
            'semAutorizacao' => $this->semAutorizacao, 'repetidos' => $this->repetidos, 'invalidos' => $this->invalidos,
            'puladosPeloIntervalo' => $this->puladosPeloIntervalo,
            'exemplo' => $this->exemplo?->paraArray(),
            'amostra' => array_map(static fn (DestinatarioDaCampanha $d): array => $d->paraArray(), $this->amostra),
            'importacao' => $this->importacao?->paraArray(),
            'filtro' => [
                'ativo' => $this->quantosFiltros > 0, 'quantos' => $this->quantosFiltros, 'base' => $this->base ?? $this->destinatarios,
                'atendem' => $this->destinatarios, 'semInformacao' => $this->semInformacao,
            ],
        ];
    }
}
