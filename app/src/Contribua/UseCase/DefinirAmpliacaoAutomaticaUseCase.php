<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;

/**
 * A chave "Ampliação de GB automática" do checkout (desenho 08 VS 03.11.26): liga ou desliga, por escritório.
 *
 * Quem dispara: o usuário primário, ao clicar na chave (grava na hora, sem esperar o pagamento). Fluxo principal:
 * o espaço do escritório (nasce no plano atual, o Free, se ainda não existia) guarda a escolha. Só grava: a dobra
 * automática em si (passar do limite, dobrar espaço e valor na assinatura do gateway, avisar o escritório) é o PR
 * seguinte da frente. Quem não é o primário recebe a mesma recusa da assinatura (D4).
 */
final class DefinirAmpliacaoAutomaticaUseCase
{
    public function __construct(
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly PlanoRepository $planos,
    ) {
    }

    public function executar(Solicitante $quem, bool $ligada): bool
    {
        if (!$quem->ehPrimario) {
            throw new \DomainException('Quem amplia o espaço é o usuário primário do escritório.');
        }
        $espaco = $this->espacos->doTenantOuNovo($quem->tenantId(), $this->planos->free());
        $espaco->definirAmpliacaoAutomatica($ligada);
        $this->espacos->salvar($espaco, flush: true);

        return $espaco->isAmpliacaoAutomatica();
    }
}
