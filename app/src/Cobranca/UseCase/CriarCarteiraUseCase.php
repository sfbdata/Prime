<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\DTO\CriarCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Exception\ClienteCredorNaoEncontradoException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cria uma Carteira de Cobrança para um Cliente credor do escritório (SPEC §4).
 *
 * História: um gestor abre uma nova operação de cobrança para um credor já cadastrado, escolhendo o
 * modo (A/B — SPEC §6), a regra padrão de honorários (SPEC §18) e ajustes de UI/alerta. Toda carteira
 * pertence a exatamente um credor (invariável 4), que precisa existir DENTRO do próprio escritório:
 * o credor é resolvido por id + tenant (guarda multi-tenant), nunca aceitando cliente de outro
 * escritório. Credor inexistente/de outro tenant é erro de entrada (ClienteCredorNaoEncontradoException),
 * tratado no controller.
 */
final class CriarCarteiraUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ClienteRepository $clienteRepository,
    ) {
    }

    public function executar(CriarCarteiraInput $input, Tenant $tenant, User $criadoPor): Carteira
    {
        // Guarda multi-tenant: só um credor do próprio escritório pode ancorar a carteira.
        $cliente = $this->clienteRepository->findOneBy(['id' => $input->clienteId, 'tenant' => $tenant]);

        if ($cliente === null) {
            throw new ClienteCredorNaoEncontradoException((int) $input->clienteId);
        }

        $carteira = new Carteira();
        $carteira->setTenant($tenant);
        $carteira->setCliente($cliente);
        $carteira->setNome((string) $input->nome);
        $carteira->setModo($input->modo);
        $carteira->setFormaHonorarios($input->formaHonorarios);
        $carteira->setPercentualHonorarios($input->percentualHonorarios);
        $carteira->setToleranciaAtrasoDias($input->toleranciaAtrasoDias);
        $carteira->setTipoVinculoPreferido($input->tipoVinculoPreferido);
        $carteira->setRotuloObjeto($input->rotuloObjeto);
        $carteira->setCriadoPor($criadoPor);

        $this->carteiraRepository->salvar($carteira, true);

        return $carteira;
    }
}
