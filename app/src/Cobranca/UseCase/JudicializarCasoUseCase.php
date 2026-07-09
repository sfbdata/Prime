<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoJaJudicializadoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\PastaNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;

/**
 * Judicializa um Caso de Cobrança (SPEC §16, invariável 16): decisão MANUAL do gestor quando a
 * cobrança extrajudicial vira ação judicial.
 *
 * História: quem = o gestor do escritório; o quê = muda o caso de `ativo` para `judicializado` e
 * vincula uma Pasta EXISTENTE (ligação unidirecional Caso → Pasta); por quê = passar a acompanhar a
 * cobrança pelo processo judicial SEM parar o acompanhamento financeiro — judicializar NÃO encerra o
 * caso (o saldo continua sendo derivado; invariável 16). Caso e Pasta são resolvidos por id + tenant
 * (guarda multi-tenant, invariável 1): a Pasta TEM de ser do mesmo escritório do caso, senão é como
 * se não existisse. O caso e os dois eventos ("judicialização" e "vínculo com pasta") são commitados
 * juntos (flush único no último evento).
 */
final class JudicializarCasoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(JudicializarCasoInput $input, Tenant $tenant, User $usuario): CasoCobranca
    {
        // Guarda multi-tenant: o caso tem de pertencer ao próprio escritório.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        // Caso encerrado não aceita novos movimentos (SPEC §17).
        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Judicialização é transição única: revincular pasta não é operação do MVP (SPEC §16).
        if ($caso->estaJudicializado()) {
            throw new CasoJaJudicializadoException((int) $caso->getId());
        }

        // Guarda crítica: a Pasta tem de ser do MESMO tenant do caso (resolve por id + tenant).
        // Pasta inexistente OU de outro escritório cai no mesmo erro (não vaza existência alheia).
        $pasta = $this->pastaRepository->findOneBy(['id' => (int) $input->pastaId, 'tenant' => $tenant]);

        if ($pasta === null) {
            throw new PastaNaoEncontradaException((int) $input->pastaId);
        }

        $caso->setPastaJudicial($pasta);
        $caso->setStatus(StatusCaso::Judicializado);

        // Persiste sem flush; o registro do último evento fecha a transação (caso + eventos juntos).
        $this->casoRepository->salvar($caso);

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::Judicializacao,
            $usuario,
            'Caso judicializado.',
        );

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::VinculoPasta,
            $usuario,
            sprintf('Vínculo com a pasta %s.', $pasta->getNup() ?? (string) $pasta->getId()),
            ['pastaId' => $pasta->getId()],
            flush: true,
        );

        return $caso;
    }
}
