<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * O que a agregação do banco diz da lixeira de UMA pasta, antes de saber quem lê: as linhas por
 * categoria e autor (documentos) e por autor (seções). {@see visivelPara()} aplica a regra do
 * contrato oculto e devolve as contas que a tela mostra.
 *
 * Uso interno do {@see ListarLixeiraDoEscritorioUseCase}.
 */
final class ResumoDaLixeiraDaPasta
{
    /** @var list<array{categoria: string|null, autorId: int|null, total: int, maisAntiga: string, maisRecente: string}> */
    private array $documentos = [];

    /** @var list<array{autorId: int|null, total: int, maisAntiga: string, maisRecente: string}> */
    private array $secoes = [];

    private function __construct(
        public readonly int $pastaId,
    ) {
    }

    /**
     * Junta as linhas das duas agregações por pasta. A ordem é de quem chama: ela depende do que a
     * pessoa vê (a data do contrato oculto não pode decidir a posição da pasta).
     *
     * @param list<array{pastaId: int, categoria: string|null, autorId: int|null, total: int, maisAntiga: string, maisRecente: string}> $documentos
     * @param list<array{pastaId: int, autorId: int|null, total: int, maisAntiga: string, maisRecente: string}>                        $secoes
     *
     * @return list<self>
     */
    public static function agrupar(array $documentos, array $secoes): array
    {
        $porPasta = [];
        foreach ($documentos as $linha) {
            $resumo = $porPasta[$linha['pastaId']] ??= new self($linha['pastaId']);
            $resumo->documentos[] = $linha;
        }
        foreach ($secoes as $linha) {
            $resumo = $porPasta[$linha['pastaId']] ??= new self($linha['pastaId']);
            $resumo->secoes[] = $linha;
        }

        return array_values($porPasta);
    }

    /**
     * As contas para quem tem `$abasOcultas` nesta pasta: o contrato de honorários com o Financeiro
     * oculto sai (regra `VisibilidadeDasAbasDaPasta::documentoVisivel`). NULL quando não sobra nada.
     *
     * @param list<string> $abasOcultas
     *
     * @return array{documentos: int, subpastas: int, maisAntiga: string, maisRecente: string, autores: list<int>, semAutor: bool}|null
     */
    public function visivelPara(array $abasOcultas): ?array
    {
        $documentos = array_values(array_filter(
            $this->documentos,
            static fn (array $l): bool => VisibilidadeDasAbasDaPasta::documentoVisivel($l['categoria'], $abasOcultas),
        ));
        $linhas = [...$documentos, ...$this->secoes];
        if ($linhas === []) {
            return null;
        }

        $autores = array_map(static fn (array $l): ?int => $l['autorId'], $linhas);

        return [
            'documentos'  => array_sum(array_column($documentos, 'total')),
            'subpastas'   => array_sum(array_column($this->secoes, 'total')),
            'maisAntiga'  => min(array_column($linhas, 'maisAntiga')),
            'maisRecente' => max(array_column($linhas, 'maisRecente')),
            'autores'     => array_values(array_unique(array_filter($autores, static fn (?int $id): bool => $id !== null))),
            'semAutor'    => \in_array(null, $autores, true),
        ];
    }
}
