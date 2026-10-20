<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CadeadoDaPastaOutput;
use App\Pasta\DTO\ConfigurarCadeadoInput;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;

/**
 * "Trancar pasta" / "Destrancar pasta" do botão direito da Lista do Expediente (desenho 1.1, E12), agora
 * sobre o cadeado (spec pasta-cadeado: o cadeado substitui a tranca binária).
 *
 * Pasta livre: trancar abre o cadeado "Ocultar para todos", o mesmo que a tranca antiga fazia. Pasta com
 * cadeado, de qualquer modo: destrancar remove o cadeado. A regra fina (por pessoa) é no modal da pasta.
 * Quem configura, o histórico e a auditoria são os do `ConfigurarCadeadoDaPastaUseCase` e do
 * `RemoverCadeadoDaPastaUseCase`, para a Lista não ter uma regra própria.
 */
final class AlternarTrancaDaPastaUseCase
{
    public function __construct(
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ConfigurarCadeadoDaPastaUseCase $configurar,
        private readonly RemoverCadeadoDaPastaUseCase $remover,
    ) {
    }

    public function executar(Pasta $pasta, User $usuario, Tenant $tenant): CadeadoDaPastaOutput
    {
        if ($pasta->getTenant() === $tenant && $this->acessoAPasta->cadeadoDe($pasta) !== null) {
            return $this->remover->executar($pasta, $usuario, $tenant);
        }

        return $this->configurar->executar(new ConfigurarCadeadoInput($pasta, $usuario, $tenant, ModoDoCadeado::Todos));
    }
}
