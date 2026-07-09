<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ResolverRevisaoInput;
use App\Cobranca\Entity\RevisaoPessoaCobrada;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\RevisaoJaResolvidaException;
use App\Cobranca\Exception\RevisaoNaoEncontradaException;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Resolve uma pendência de Revisão de Pessoa Cobrada (SPEC §8, invariável 28: o sistema alerta, o
 * humano decide).
 *
 * História: o gestor decide manualmente manter ou substituir a pessoa cobrada e registra a resolução.
 * A revisão é resolvida por id + tenant (guarda multi-tenant) e só transiciona se ainda estiver
 * pendente (resolução é única). Depois de resolvida, o mesmo evento NÃO continua gerando alerta (§8).
 * A revisão e o evento "revisão de vínculo" são commitados juntos (flush único no evento).
 */
final class ResolverRevisaoUseCase
{
    public function __construct(
        private readonly RevisaoPessoaCobradaRepository $revisaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(ResolverRevisaoInput $input, Tenant $tenant, User $usuario): RevisaoPessoaCobrada
    {
        // Guarda multi-tenant: a revisão tem de pertencer ao próprio escritório.
        $revisao = $this->revisaoRepository->findOneByIdDoTenant((int) $input->revisaoId, $tenant);

        if ($revisao === null) {
            throw new RevisaoNaoEncontradaException((int) $input->revisaoId);
        }

        // Resolução é única: uma revisão já resolvida não transiciona de novo (SPEC §8).
        if (!$revisao->estaPendente()) {
            throw new RevisaoJaResolvidaException((int) $revisao->getId());
        }

        $revisao->resolver((string) $input->resolucao, $usuario);

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->revisaoRepository->salvar($revisao);

        $this->registrarEvento->registrar(
            $revisao->getCaso(),
            TipoEventoHistorico::RevisaoVinculo,
            $usuario,
            'Revisão de vínculo resolvida.',
            null,
            flush: true,
        );

        return $revisao;
    }
}
