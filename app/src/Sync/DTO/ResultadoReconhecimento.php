<?php

declare(strict_types=1);

namespace App\Sync\DTO;

/** Contadores de uma rodada do reconhecimento do Drive ({@see \App\Sync\UseCase\ReconhecerPastasDoDriveUseCase}). */
final class ResultadoReconhecimento
{
    /** Pastas do sistema ligadas sozinhas (casamento único). */
    public int $pastasLigadas = 0;

    /** Documentos ligados a arquivos que já estavam no Drive. */
    public int $arquivosLigados = 0;

    /** Dúvidas novas gravadas para o administrador decidir. */
    public int $pendenciasNovas = 0;

    /** Subpastas do Drive puladas porque já tinham registro (pendente ou decidido). */
    public int $jaRegistradas = 0;
}
