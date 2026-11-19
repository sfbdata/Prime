<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Repository\MensagemSuporteRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Marca ou desfaz a resposta de uma mensagem do "Falar com suporte". Clicar em "Responder por e-mail" marca; o botão
 * "Desfazer" volta a mensagem para pendente. Quem pode é decidido no controller (Master ou o destinatário do suporte).
 */
final class MarcarRespostaDoSuporteUseCase
{
    public function __construct(private readonly MensagemSuporteRepository $mensagens)
    {
    }

    public function executar(int $id, bool $respondida, \DateTimeImmutable $agora): void
    {
        $mensagem = $this->mensagens->find($id);
        if ($mensagem === null) {
            throw new NotFoundHttpException();
        }

        if ($respondida) {
            $mensagem->marcarRespondida($agora);
        } else {
            $mensagem->desfazerResposta();
        }

        $this->mensagens->salvar($mensagem, true);
    }
}
