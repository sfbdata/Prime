<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Exception\AnotacaoNaoEditavelException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\EventoNaoEncontradoException;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Apaga uma ANOTAÇÃO da linha do tempo (ajuste 2026-07-22, decisão do dono).
 *
 * Exclusão DEFINITIVA, como na timeline da Pasta: a linha some do histórico, não fica marca de
 * "removida". Consequência aceita conscientemente pelo dono — a anotação conta como trabalho de
 * cobrança na Central (§5.1 da spec da Central), então apagar reduz o próprio histórico de quem
 * apagou. Vale só dentro das 48h e só para o autor; passada a janela, o registro fica firme.
 *
 * As mesmas três guardas do editar, na mesma fonte (`EventoHistorico::podeSerEditadaPor`): tem de ser
 * anotação, do próprio autor, dentro da janela. Evento automático (contato, acordo, pagamento) nunca
 * é apagável por aqui — apagá-lo destruiria o registro do que o sistema fez.
 */
final class ExcluirAnotacaoUseCase
{
    public function __construct(
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int id do caso a que a anotação pertencia (o chamador redireciona para lá) */
    public function executar(int $eventoId, Tenant $tenant, User $usuario): int
    {
        // Guarda multi-tenant: evento de outro escritório não existe para quem pede.
        $evento = $this->eventoRepository->findOneByIdDoTenant($eventoId, $tenant);

        if ($evento === null) {
            throw new EventoNaoEncontradoException($eventoId);
        }

        $caso = $evento->getCaso();
        if ($caso !== null && $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        if (!$evento->podeSerEditadaPor($usuario, $this->clock->now())) {
            throw new AnotacaoNaoEditavelException();
        }

        $casoId = (int) $caso?->getId();
        $this->eventoRepository->remover($evento, true);

        return $casoId;
    }
}
