<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Apagar para todos" uma mensagem do chat (spec VS 18.10.26 §3.4). Quem: o autor, nas próprias
 * mensagens normais e notas internas; e o Master, que pode tudo (regra do dono). A mensagem fica
 * na conversa como "apagada", sem o texto. Provisório até o zip do desenho confirmar a regra.
 */
final class ApagarMensagemParaTodosUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $mensagemId): void
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $mensagem = null;
        foreach ($demanda->getMensagens() as $m) {
            if ((int) $m->getId() === $mensagemId && $this->politica->mensagemVisivel($ctx, $demanda, $m)) {
                $mensagem = $m;
            }
        }
        if ($mensagem === null) {
            throw new DemandaNaoEncontrada();
        }
        if (!$this->politica->podeApagarMensagem($ctx, $mensagem)) {
            throw new AcaoNaoPermitida('Você só pode apagar as suas próprias mensagens.');
        }

        $mensagem->apagar(new \DateTimeImmutable());
        $this->em->flush();
    }
}
