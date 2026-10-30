<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\PropostaOutput;
use App\ChatIa\Exception\PropostaEncerradaException;
use App\ChatIa\Exception\PropostaNaoEncontradaException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\MontadorDoCartaoDaProposta;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Cancelar a proposta: ela vira `cancelada`, perde os dados e nada é gravado em Cliente nem Pasta. Só o dono cancela
 * (alheia = 404). Já confirmada, cancelada ou vencida: 409, na mesma trava de linha do Confirmar.
 *
 * @throws SemAcessoAoChatException
 * @throws PropostaNaoEncontradaException
 * @throws PropostaEncerradaException
 */
final class CancelarPropostaDeCadastroUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly PropostaDeCadastroRepository $propostas,
        private readonly MontadorDoCartaoDaProposta $cartao,
        private readonly TransacaoDoChat $transacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $id): PropostaOutput
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        if ($id <= 0 || $this->propostas->doDono($id, $tenant, $eu) === null) {
            throw new PropostaNaoEncontradaException();
        }
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $desfecho = $this->transacao->executar(function () use ($id, $eu, $tenant, $agora): PropostaOutput|\Throwable {
            $proposta = $this->propostas->doDonoComTrava($id, $tenant, $eu);
            if ($proposta === null) {
                return new PropostaNaoEncontradaException();
            }
            if (!$proposta->estaPendente()) {
                return PropostaEncerradaException::jaDecidida($proposta->getEstado());
            }
            if ($proposta->estaVencida($agora)) {
                $proposta->expirar($agora);

                return PropostaEncerradaException::vencida();
            }
            $proposta->cancelar($agora);

            return $this->cartao->montar($proposta, $agora);
        });
        if ($desfecho instanceof \Throwable) {
            throw $desfecho;
        }

        return $desfecho;
    }
}
