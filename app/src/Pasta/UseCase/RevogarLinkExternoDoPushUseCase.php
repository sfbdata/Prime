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
 * "Revogar" da lista "Links desta pasta" (spec push-link-externo §2.2): quem tiver o endereço perde o
 * acesso na hora. Vale para link ativo ou expirado; revogar de novo não muda nada (idempotente) e
 * não há desfazer. O link chega já conferido (desta pasta e deste escritório) pelo controller.
 */
final class RevogarLinkExternoDoPushUseCase
{
    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
        private readonly MontadorDeLinksExternos $montador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(LinkExternoDoPush $link, User $usuario): LinkExternoDoPushOutput
    {
        $agora = $this->relogio->now();
        if (!$link->estaRevogado()) {
            $link->revogar($usuario, $agora);
            $this->links->salvar($link, true);
        }

        return $this->montador->montarUm($link, $link->getTenant(), $agora);
    }
}
