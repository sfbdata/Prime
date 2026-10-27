<?php

declare(strict_types=1);

namespace App\Contribua\MessageHandler;

use App\Contribua\Message\ReconsultarCobrancaNoBanco;
use App\Contribua\Repository\NotificacaoRecebidaRepository;
use App\Contribua\UseCase\ConfirmarCobrancaPeloBancoUseCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Reconsulta a cobrança avisada; falha do banco sobe para o Messenger tentar de novo (e a conciliação cobre). */
#[AsMessageHandler]
final class ReconsultarCobrancaNoBancoHandler
{
    public function __construct(
        private readonly ConfirmarCobrancaPeloBancoUseCase $confirmar,
        private readonly NotificacaoRecebidaRepository $avisos,
    ) {
    }

    public function __invoke(ReconsultarCobrancaNoBanco $mensagem): void
    {
        $this->confirmar->executar($mensagem->banco, $mensagem->idNoBanco);
        if (null !== $mensagem->idEvento) {
            $this->avisos->marcarProcessada($mensagem->banco, $mensagem->idEvento);
        }
    }
}
