<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\GerarRevisaoInput;
use App\Cobranca\Entity\RevisaoPessoaCobrada;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Gera uma pendência de Revisão de Pessoa Cobrada (SPEC §8, invariável 28: o sistema alerta, o humano
 * decide).
 *
 * História: uma mudança relevante de vínculo com o objeto (ex.: a pessoa cobrada deixou de ser
 * proprietária) NÃO troca a pessoa cobrada automaticamente — a cobrança segue na pessoa anterior
 * (invariáveis 9/10). O gestor apenas registra a pendência com o motivo; enquanto pendente, ela
 * alimenta o alerta de revisão (§14). O caso é resolvido por id + tenant (guarda multi-tenant); a
 * revisão e o evento "revisão de vínculo" são commitados juntos (flush único no evento).
 */
final class GerarRevisaoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RevisaoPessoaCobradaRepository $revisaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(GerarRevisaoInput $input, Tenant $tenant, User $usuario): RevisaoPessoaCobrada
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        $motivo = (string) $input->motivo;

        $revisao = (new RevisaoPessoaCobrada())
            ->setTenant($caso->getTenant())
            ->setCaso($caso)
            ->setMotivo($motivo)
            ->setCriadoPor($usuario);

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->revisaoRepository->salvar($revisao);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::RevisaoVinculo,
            $usuario,
            'Revisão de vínculo gerada.',
            ['motivo' => $motivo],
            flush: true,
        );

        return $revisao;
    }
}
