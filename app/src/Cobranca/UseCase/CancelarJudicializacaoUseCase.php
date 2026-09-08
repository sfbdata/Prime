<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CancelarJudicializacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\CasoNaoJudicializadoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cancela MANUALMENTE a judicialização de um Caso de Cobrança: desvincula a pasta e volta o status
 * para `Ativo`. Decisão do dono (2026-09-08) — disponível sempre que o caso estiver judicializado.
 *
 * Existe para dois problemas reais medidos em produção:
 *
 * - a pasta vinculada pode ter sido excluída (lápide ou remoção física) sem que nada, em Cobrança,
 *   fosse avisado — `ExcluirPastaUseCase` nunca toca em `CasoCobranca`. O caso ficava preso como
 *   `Judicializado` para sempre, sem caminho de volta (a judicialização nasceu como "transição
 *   única", SPEC §16, e não havia UseCase de desfazer);
 * - a pasta pode ter sido vinculada por engano a outro caso (ver a guarda nova em
 *   `JudicializarCasoUseCase::pastaExistenteDoTenant`) — cancelar é o jeito de desfazer o engano sem
 *   mexer direto no banco.
 *
 * Por isso este UseCase NÃO verifica se a pasta ainda existe ou está excluída: limpa o vínculo (que
 * pode já estar `null`) e devolve o caso a `Ativo`, incondicionalmente, desde que ele esteja
 * `Judicializado`. Um caso `Encerrado` já não é `estaJudicializado()` — o enum é de valor único —,
 * então a guarda é uma só.
 */
final class CancelarJudicializacaoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(CancelarJudicializacaoInput $input, Tenant $tenant, User $usuario): CasoCobranca
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        if (!$caso->estaJudicializado()) {
            throw new CasoNaoJudicializadoException((int) $caso->getId());
        }

        $pastaAnterior = $caso->getPastaJudicial();

        $caso->setPastaJudicial(null);
        $caso->setStatus(StatusCaso::Ativo);

        // Persiste sem flush; o registro do evento fecha a transação.
        $this->casoRepository->salvar($caso);

        $motivo = trim((string) $input->motivo);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::JudicializacaoCancelada,
            $usuario,
            sprintf('Judicialização cancelada: %s', $motivo),
            ['motivo' => $motivo, 'pastaAnteriorId' => $pastaAnterior?->getId()],
            flush: true,
        );

        return $caso;
    }
}
