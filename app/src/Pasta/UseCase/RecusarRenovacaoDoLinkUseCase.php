<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\DTO\LinkExternoDoPushOutput;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\MontadorDeLinksExternos;
use Psr\Clock\ClockInterface;

/**
 * "Não renovar" da lista "Links desta pasta" (spec push-link-externo §2.4). Só com pedido de
 * renovação pendente; a resposta fica `recusado` e a página pública passa a dizer "O escritório não
 * aceitou a renovação", sem oferecer novo pedido.
 */
final class RecusarRenovacaoDoLinkUseCase
{
    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
        private readonly MontadorDeLinksExternos $montador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(LinkExternoDoPush $link, User $usuario): LinkExternoDoPushOutput
    {
        $link->recusarRenovacao();
        $this->links->salvar($link, true);

        return $this->montador->montarUm($link, $link->getTenant(), $this->relogio->now());
    }
}
