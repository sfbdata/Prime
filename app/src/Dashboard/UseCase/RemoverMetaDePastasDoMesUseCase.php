<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\MetaDePastasGravadaOutput;
use App\Dashboard\DTO\RemoverMetaDePastasInput;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Dashboard\Exception\MetaDePastasNaoPermitidaException;
use App\Dashboard\Repository\DashboardMetaPastasRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * Remove a meta de pastas (DASH-016; desenho `salvarMeta(null)`): grava uma linha com valor 0 em `mes` =
 * dia 1º do mês corrente, o que encerra a meta a partir dele (valor 0 vale como "sem meta"; os meses
 * anteriores seguem com a que tinham). Se o escritório já está sem meta vigente, não grava nada.
 *
 * Mesma régua do definir: só um primário ativo (403 para os outros).
 */
final class RemoverMetaDePastasDoMesUseCase
{
    public function __construct(
        private readonly DashboardMetaPastasRepository $repository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    public function executar(RemoverMetaDePastasInput $input): MetaDePastasGravadaOutput
    {
        if (!$this->regraDosPrimarios->podeGerirPrimarios($input->autor, $input->tenant)) {
            throw new MetaDePastasNaoPermitidaException('Só o usuário primário pode remover a meta de pastas.');
        }

        $vigente = $this->repository->buscarVigenteEm($input->tenant, $input->agora);
        if ($vigente === null || $vigente->getValor() === 0) {
            return new MetaDePastasGravadaOutput(0, $input->agora->format('Y-m'));
        }

        $meta = new DashboardMetaPastas($input->tenant, $input->agora, 0, $input->autor, $input->agora);
        $this->repository->salvar($meta, flush: true);

        return MetaDePastasGravadaOutput::fromEntity($meta);
    }
}
