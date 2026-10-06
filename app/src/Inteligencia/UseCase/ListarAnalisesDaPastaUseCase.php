<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\AnalisesDaPastaOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\Entity\Pasta;

/**
 * As análises de uma pasta para a aba Push (mais recente primeiro, sem excluídas) e o que o botão
 * precisa: se já houve análise e quantas movimentações a última concluída ainda não leu.
 *
 * Recebe a Pasta já resolvida e conferida pelo chamador (tenant + `canAccessResource`): quem lista
 * é o `PastaController::show` (1B) e o `AnalisePushController`, ambos com o guarda próprio.
 */
final class ListarAnalisesDaPastaUseCase
{
    public const LIMITE = 20;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly MontadorDeContextoDoPush $montador,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): AnalisesDaPastaOutput
    {
        $alvoId = (int) $pasta->getId();
        $entidades = $this->analises->listarPorAlvo($tenant, AnaliseDeInteligencia::ALVO_PASTA, $alvoId, self::LIMITE);

        $saidas = [];
        $ultimaConcluida = null;
        foreach ($entidades as $entidade) {
            $saida = AnaliseOutput::fromEntity($entidade);
            $saidas[] = $saida;
            if ($ultimaConcluida === null && $entidade->estaConcluida()) {
                $ultimaConcluida = $entidade;
            }
        }

        $chavesAtuais = $this->montador->chavesAtuais($tenant, $pasta);
        $jaAnalisadas = $ultimaConcluida?->getChavesAnalisadas() ?? [];

        return new AnalisesDaPastaOutput(
            analises: $saidas,
            ultimaConcluida: $ultimaConcluida !== null ? AnaliseOutput::fromEntity($ultimaConcluida) : null,
            totalMovimentacoes: count($chavesAtuais),
            naoAnalisadas: count(array_diff($chavesAtuais, $jaAnalisadas)),
        );
    }
}
