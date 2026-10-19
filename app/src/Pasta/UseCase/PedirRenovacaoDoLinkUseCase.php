<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Notificacao;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Service\NotificacaoService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Pedir renovação do link" da página pública expirada (spec push-link-externo §2.6, decisão D2).
 *
 * Quem: o terceiro, sem login. Só vale para link expirado, não revogado e sem pedido pendente ou
 * recusado; fora disso não faz nada e devolve `false` (a página mostra o estado real de novo).
 * O pedido vira aviso no sino e na Caixa da Central para quem CRIOU o link, com o link para a aba
 * Push da pasta, onde ficam "Renovar 30 dias" e "Não renovar".
 */
final class PedirRenovacaoDoLinkUseCase
{
    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(string $endereco, ?string $nome, ?string $mensagem): bool
    {
        $agora = $this->relogio->now();
        $link = $this->links->findOnePeloEndereco($endereco);
        if ($link === null || $link->getPasta()->estaExcluida() || !$link->aceitaPedidoDeRenovacao($agora)) {
            return false;
        }

        $link->pedirRenovacao($nome, $mensagem, $agora);
        $this->links->salvar($link);
        $this->avisarQuemCriou($link);

        return true;
    }

    /** `criarNotificacao` grava com flush: o pedido e o aviso vão na mesma descarga. */
    private function avisarQuemCriou(LinkExternoDoPush $link): void
    {
        $quem = $link->getRenovacaoNome() ?? 'Quem recebeu o link';
        $texto = sprintf(
            '%s pediu a renovação do link externo do Push da Pasta %s.',
            $quem,
            (string) $link->getPasta()->getNup(),
        );
        $url = $this->urlGenerator->generate('pasta_show', ['id' => $link->getPasta()->getId()]) . '#push';

        $this->notificacoes->criarNotificacao($link->getCriadoPor(), $link->getTenant(), Notificacao::TIPO_PUSH_LINK_RENOVACAO, $texto, $url);
    }
}
