<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AlterarPessoaCobradaInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Troca manualmente a pessoa cobrada de um Caso (SPEC §8).
 *
 * História: quando o responsável real pela dívida muda (venda, sucessão, correção de titularidade),
 * o gestor aponta a nova pessoa cobrada e registra o MOTIVO (obrigatório — SPEC §8.9). É decisão
 * manual: NÃO afeta a dívida, os pagamentos nem os acordos já existentes (invariáveis 9/10) — só
 * passa a apontar para quem cobrar daqui em diante, deixando a troca no histórico. Caso e nova
 * pessoa são resolvidos por id + tenant (guarda multi-tenant); inexistente ou de outro escritório
 * interrompe a operação (CasoNaoEncontradoException / PessoaNaoEncontradaException).
 */
final class AlterarPessoaCobradaUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly PessoaRepository $pessoaRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(AlterarPessoaCobradaInput $input, Tenant $tenant, User $usuario): CasoCobranca
    {
        // Guarda multi-tenant: só um caso do próprio escritório pode ter a pessoa cobrada alterada.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Guarda multi-tenant: a nova pessoa também precisa ser do próprio escritório.
        $novaPessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->novaPessoaCobradaId, $tenant);

        if ($novaPessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->novaPessoaCobradaId);
        }

        $anterior = $caso->getPessoaCobradaAtual();

        // Troca manual: não mexe em dívida/pagamentos/acordos (SPEC §8, invariáveis 9/10).
        $caso->definirPessoaCobrada($novaPessoa);

        // O caso é managed: o flush do evento commita, na mesma transação, a troca + o evento.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::PessoaCobradaAlterada,
            $usuario,
            'Pessoa cobrada alterada.',
            [
                'de' => $anterior?->getId(),
                'para' => $novaPessoa->getId(),
                'motivo' => trim((string) $input->motivo),
            ],
            flush: true,
        );

        return $caso;
    }
}
