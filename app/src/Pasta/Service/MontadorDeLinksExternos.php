<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\LinkExternoDoPushOutput;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Repository\AcessoAoLinkExternoDoPushRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Monta a saída de um ou mais links (estado, URL absoluta, acessos agregados) com uma consulta de
 * acessos para todos eles. A URL é a curta, já ativa (decisão D3 do dono): `/p/<endereco>`.
 */
final class MontadorDeLinksExternos
{
    public function __construct(
        private readonly AcessoAoLinkExternoDoPushRepository $acessos,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ConteudoDoPushCompartilhado $conteudo,
    ) {
    }

    /**
     * @param LinkExternoDoPush[] $links
     *
     * @return LinkExternoDoPushOutput[]
     */
    public function montar(array $links, Tenant $tenant, \DateTimeImmutable $agora): array
    {
        $ids = array_map(static fn (LinkExternoDoPush $l): int => (int) $l->getId(), $links);
        $estatisticas = $this->acessos->estatisticasDosLinks($ids, $tenant);

        return array_map(
            fn (LinkExternoDoPush $l): LinkExternoDoPushOutput => LinkExternoDoPushOutput::fromEntity(
                $l,
                $this->url($l),
                $estatisticas[(int) $l->getId()] ?? ['total' => 0, 'ultimo' => null, 'porDia' => [], 'ultimos' => []],
                $agora,
                $this->conteudo->linkVisivel($l),
            ),
            $links,
        );
    }

    public function montarUm(LinkExternoDoPush $link, Tenant $tenant, \DateTimeImmutable $agora): LinkExternoDoPushOutput
    {
        return $this->montar([$link], $tenant, $agora)[0];
    }

    public function url(LinkExternoDoPush $link): string
    {
        return $this->urlGenerator->generate(
            'push_compartilhado',
            ['endereco' => $link->getEndereco()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
