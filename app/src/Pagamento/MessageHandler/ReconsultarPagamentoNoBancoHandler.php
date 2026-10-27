<?php

declare(strict_types=1);

namespace App\Pagamento\MessageHandler;

use App\Contribua\Repository\NotificacaoRecebidaRepository;
use App\Pagamento\Message\ReconsultarPagamentoNoBanco;
use App\Pagamento\UseCase\ConfirmarPagamentoPeloBancoUseCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Falha do banco (timeout) sobe: o Messenger repete, e o aviso só é dado por processado depois de a reconsulta terminar.
 */
#[AsMessageHandler]
final class ReconsultarPagamentoNoBancoHandler
{
    public function __construct(
        private readonly ConfirmarPagamentoPeloBancoUseCase $confirmar,
        private readonly NotificacaoRecebidaRepository $avisos,
    ) {
    }

    public function __invoke(ReconsultarPagamentoNoBanco $mensagem): void
    {
        $this->confirmar->executar($mensagem->banco, $mensagem->idNoBanco);
        if ($mensagem->idEvento !== null) {
            $this->avisos->marcarProcessada($mensagem->banco, $mensagem->idEvento);
        }
    }
}
