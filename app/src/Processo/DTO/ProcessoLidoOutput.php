<?php

declare(strict_types=1);

namespace App\Processo\DTO;

use App\Pasta\DTO\PastaVinculadaOutput;

/**
 * O processo como a tela dele mostra, para a leitura sem sessão ({@see \App\Processo\UseCase\LerProcessoUseCase}):
 * os dados do cartão, as partes (nome, tipo e papel; nunca o documento), uma página das movimentações, as notas
 * técnicas e as pastas vinculadas, já com o cadeado aplicado (a oculta vem marcada). Só texto cru do banco: quem
 * entrega a terceiros neutraliza e mascara.
 */
final readonly class ProcessoLidoOutput
{
    /**
     * @param list<array{tipo: string, papel: ?string, nome: string}>                                   $partes
     * @param list<array{data: ?string, descricao: string, tipo: ?string, orgao: ?string, complementos: ?string}> $movimentacoes
     * @param list<NotaTecnicaOutput>                                                                     $notas
     * @param list<PastaVinculadaOutput>                                                                  $pastas
     */
    public function __construct(
        public int $id,
        public string $numero,
        public string $tribunal,
        public string $classe,
        public string $assunto,
        public string $orgaoJulgador,
        public string $instancia,
        public string $situacao,
        public ?string $distribuidoEm,
        public ?string $baixadoEm,
        public array $partes,
        public int $totalDePartes,
        public array $movimentacoes,
        public int $paginaDasMovimentacoes,
        public bool $haMaisMovimentacoes,
        public array $notas,
        public array $pastas,
    ) {
    }
}
