<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Cartão do colaborador (botão direito no nome): a posição no ranking de metas concluídas na
 * janela escolhida (Mês, Trimestre, Semestre, Ano) e os registros dele na mesma janela
 * (spec dashboard-parte-1, item 4). Os textos ("2º de 9 no trimestre") são montados no JS pelo
 * catálogo; aqui só números e datas.
 */
final class RankingDoColaboradorOutput
{
    public function __construct(
        public readonly int $userId,
        public readonly string $nome,
        public readonly ?string $cargo,
        public readonly string $janela,
        public readonly string $dataDe,
        public readonly string $dataAte,
        public readonly int $posicao,
        public readonly int $pessoas,
        public readonly int $metasConcluidas,
        public readonly int $metasConcluidasEquipe,
        public readonly int $registros,
        public readonly bool $campeao,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'userId'             => $this->userId,
            'nome'               => $this->nome,
            'cargo'              => $this->cargo,
            'janela'             => $this->janela,
            'dataDe'             => $this->dataDe,
            'dataAte'            => $this->dataAte,
            'posicao'            => $this->posicao,
            'pessoas'            => $this->pessoas,
            'metasConcluidas'       => $this->metasConcluidas,
            'metasConcluidasEquipe' => $this->metasConcluidasEquipe,
            'registros'          => $this->registros,
            'campeao'            => $this->campeao,
        ];
    }
}
