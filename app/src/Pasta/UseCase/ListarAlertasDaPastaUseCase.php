<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AlertasDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\AlertasDaPasta;
use Psr\Clock\ClockInterface;

/**
 * Os alertas da Controladoria da pasta (metas e prazos vencidos, pasta parada e os do Cadastro), como o botão
 * Controladoria da tela da pasta (`pasta_controladoria`) e a parte `controladoria` da `ler_pasta` do conector de I.A.
 *
 * Quem: alguém que vê a pasta, com o cadeado ({@see AcessoAPasta::podeVer()}). A regra dos alertas é a do
 * {@see AlertasDaPasta}; aqui ficam a posse do escritório, a guarda de ver e a contagem dos urgentes.
 *
 * Só leitura: nada é gravado.
 */
final class ListarAlertasDaPastaUseCase
{
    public function __construct(
        private readonly AlertasDaPasta $alertas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaVerPastaException
     */
    public function executar(Pasta $pasta, User $quem, Tenant $tenant): AlertasDaPastaOutput
    {
        $doEscritorio = $pasta->getTenant() === $tenant
            || ($tenant->getId() !== null && $pasta->getTenant()?->getId() === $tenant->getId());
        if (!$doEscritorio) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeVer($quem, $pasta)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }

        return AlertasDaPastaOutput::de($this->alertas->montar($pasta, $tenant, $quem, $this->clock->now()));
    }
}
