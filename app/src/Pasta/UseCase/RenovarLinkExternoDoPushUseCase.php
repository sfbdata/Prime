<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\DTO\LinkExternoDoPushOutput;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Exception\LinkExternoDoPushException;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\ConteudoDoPushCompartilhado;
use App\Pasta\Service\MontadorDeLinksExternos;
use Psr\Clock\ClockInterface;

/**
 * "Renovar 30 dias" da lista "Links desta pasta" (spec push-link-externo §2.3). Só link expirado e
 * não revogado; a validade passa a ser agora + 30 dias e o mesmo endereço volta a abrir. Havendo
 * pedido do terceiro pendente, ele fica respondido como `renovado`.
 *
 * `$usuario` fica no contrato (quem renovou) mesmo sem coluna própria: a lista mostra só a validade.
 */
final class RenovarLinkExternoDoPushUseCase
{
    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
        private readonly MontadorDeLinksExternos $montador,
        private readonly ConteudoDoPushCompartilhado $conteudo,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(LinkExternoDoPush $link, User $usuario): LinkExternoDoPushOutput
    {
        $agora = $this->relogio->now();
        if (!$this->conteudo->linkVisivel($link)) {
            throw LinkExternoDoPushException::renovarIndisponivel();
        }
        $link->renovar($agora);
        $this->links->salvar($link, true);

        return $this->montador->montarUm($link, $link->getTenant(), $agora);
    }
}
