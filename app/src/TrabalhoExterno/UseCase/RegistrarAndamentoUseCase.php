<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeExecucao;

/** "Registrar andamento da execução": vai para o chat como andamento. Mesmas regras do checklist. */
final class RegistrarAndamentoUseCase
{
    public function __construct(
        private readonly GuardaDeExecucao $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, string $texto): DemandaExterna
    {
        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) > EnviarMensagemUseCase::MAX_CARACTERES) {
            throw new DadosInvalidos('Descreva o andamento (até 5000 caracteres).');
        }

        [$demanda, $ctx] = $this->guarda->demandaEmExecucao($ctx, $codigo);
        $demanda->registrar($ctx->autor(), $texto, TipoMensagem::Andamento);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
