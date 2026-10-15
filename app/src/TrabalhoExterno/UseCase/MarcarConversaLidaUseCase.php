<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\LeituraDemanda;
use App\TrabalhoExterno\Repository\LeituraDemandaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;

/** Abrir a aba Chat marca a conversa como lida até a última mensagem (`marcarLido` do desenho). */
final class MarcarConversaLidaUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly LeituraDemandaRepository $leituras,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): void
    {
        $demanda = $this->localizador->localizar($ctx, $codigo);
        $mensagens = $demanda->getMensagens();
        $ultima = $mensagens === [] ? 0 : (int) end($mensagens)->getId();

        $leitura = $this->leituras->daDemanda($demanda, $ctx->usuario) ?? new LeituraDemanda($demanda, $ctx->usuario);
        $leitura->marcarAte($ultima);
        $this->leituras->salvar($leitura, flush: true);
    }
}
