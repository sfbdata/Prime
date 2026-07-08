<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Entity\Tenant\Tenant;

/**
 * Edita a configuração de uma Carteira de Cobrança já existente (SPEC §4).
 *
 * História: um gestor ajusta a configuração de uma carteira que já opera — muda o nome, o modo
 * (A/B — SPEC §6), a regra padrão de honorários (SPEC §18), a tolerância de atraso, o tipo de vínculo
 * preferido e o rótulo do objeto na UI. O credor NÃO muda: carteira pertence a exatamente um cliente,
 * imutável após a criação (invariável 4), então este UseCase jamais toca o `cliente` (nem o `tenant`).
 * A carteira é resolvida por id + tenant (guarda multi-tenant): só se edita carteira do PRÓPRIO
 * escritório — id inexistente ou de outro tenant é erro de entrada (CarteiraNaoEncontradaException),
 * tratado no controller. Mudar a configuração da carteira NÃO recalcula casos antigos: cada caso guarda
 * o snapshot da regra aplicada na sua abertura (SPEC §18.2), tratado em etapa futura — não é
 * responsabilidade deste UseCase. O `atualizadoEm` é preenchido automaticamente (PreUpdate) e a
 * auditoria técnica registra o ator.
 */
final class EditarConfiguracaoCarteiraUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
    ) {
    }

    public function executar(int $carteiraId, EditarConfiguracaoCarteiraInput $input, Tenant $tenant): Carteira
    {
        // Guarda multi-tenant: só uma carteira do próprio escritório pode ser editada.
        $carteira = $this->carteiraRepository->findOneByIdDoTenant($carteiraId, $tenant);

        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException($carteiraId);
        }

        // Aplica só a configuração — credor (cliente) e tenant permanecem intocados (invariável 4).
        $carteira->setNome((string) $input->nome);
        $carteira->setModo($input->modo);
        $carteira->setFormaHonorarios($input->formaHonorarios);
        $carteira->setPercentualHonorarios($input->percentualHonorarios);
        $carteira->setToleranciaAtrasoDias($input->toleranciaAtrasoDias);
        $carteira->setTipoVinculoPreferido($input->tipoVinculoPreferido);
        $carteira->setRotuloObjeto($input->rotuloObjeto);

        $this->carteiraRepository->salvar($carteira, true);

        return $carteira;
    }
}
