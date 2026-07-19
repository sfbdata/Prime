<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Entity\Tenant\Tenant;

/**
 * Edita a configuração (regras/padrões de operação) de uma Carteira de Cobrança já existente (SPEC §18).
 *
 * História: um gestor ajusta o modo (SPEC §6), a regra padrão de honorários (SPEC §18), a tolerância de
 * atraso, o tipo de vínculo preferido e o rótulo do objeto de uma carteira já aberta. Esses padrões valem
 * para NOVAS cobranças; mudar a configuração aqui NÃO recalcula casos antigos, porque cada caso guarda o
 * snapshot da regra aplicada no momento (SPEC §18.3). Nome e cliente credor não são tocados (invariável 4).
 * A carteira é resolvida por id + tenant (guarda multi-tenant): carteira inexistente/de outro escritório é
 * erro de entrada (CarteiraNaoEncontradaException), tratado no controller. O atualizadoEm é setado
 * automaticamente pela entidade (PreUpdate).
 */
final class EditarConfiguracaoCarteiraUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
    ) {
    }

    public function executar(EditarConfiguracaoCarteiraInput $input, Tenant $tenant): Carteira
    {
        // Guarda multi-tenant: só uma carteira do próprio escritório pode ser configurada.
        $carteira = $this->carteiraRepository->findOneByIdDoTenant((int) $input->carteiraId, $tenant);

        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException((int) $input->carteiraId);
        }

        $carteira->setModo($input->modo);
        $carteira->setFormaHonorarios($input->formaHonorarios);
        $carteira->setPercentualHonorarios($input->percentualHonorarios);
        $carteira->setToleranciaAtrasoDias($input->toleranciaAtrasoDias);
        $carteira->setTipoVinculoPreferido($input->tipoVinculoPreferido);
        $carteira->setRotuloObjeto($input->rotuloObjeto);

        // Encargos por atraso (nível 1 da cascata, spec §4.1): valem para as NOVAS cobranças. Caso e
        // obrigação já existentes guardam o próprio snapshot e não são recalculados (spec §5).
        $carteira->setTaxaJurosMensalBp($input->taxaJurosMensalBp);
        $carteira->setRegimeJuros($input->regimeJuros);
        $carteira->setTaxaMultaBp($input->taxaMultaBp);
        $carteira->setBaseMulta($input->baseMulta);
        $carteira->setTaxaCorrecaoBp($input->taxaCorrecaoBp);
        $carteira->setBaseCorrecao($input->baseCorrecao);
        $carteira->setBaseHonorarios($input->baseHonorarios);
        $carteira->setCarenciaHonorariosDias($input->carenciaHonorariosDias);
        $carteira->setToleranciaJurosMultaDias($input->toleranciaJurosMultaDias);

        $this->carteiraRepository->salvar($carteira, true);

        return $carteira;
    }
}
