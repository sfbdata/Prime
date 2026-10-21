<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

/**
 * A seção "Indicações do BlueJus" do Painel do Master no desenho de 21/10 (dc L234-242): só o cabeçalho
 * ("N enviadas · N hoje · N viraram conta") e os limites. A tabela por pessoa e os alertas saíram da tela; por isso
 * este resumo não os consulta.
 */
final class ResumoDeIndicacoesOutput
{
    public function __construct(
        public readonly int $enviadas,
        public readonly int $ultimas24h,
        public readonly int $viraramConta,
        public readonly LimitesDeIndicacaoOutput $limites,
    ) {
    }
}
