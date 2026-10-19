<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\DTO\LinkExternoDoPushOutput;
use App\Pasta\DTO\LinksExternosDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\ConteudoDoPushCompartilhado;
use App\Pasta\Service\MontadorDeLinksExternos;
use Psr\Clock\ClockInterface;

/**
 * Os links externos da pasta para o modal e o menu da aba Push (spec push-link-externo §3, GET):
 * mais recente primeiro, com estado e acessos; o resumo; se a pasta pode gerar um link agora (e o
 * motivo, se não pode) e quantas análises podem ir junto. A pasta chega conferida pelo controller.
 */
final class ListarLinksExternosDoPushUseCase
{
    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
        private readonly ConteudoDoPushCompartilhado $conteudo,
        private readonly MontadorDeLinksExternos $montador,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(Pasta $pasta): LinksExternosDaPastaOutput
    {
        $tenant = $pasta->getTenant() ?? throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        $agora = $this->relogio->now();
        $saidas = $this->montador->montar($this->links->listarDaPasta($pasta, $tenant), $tenant, $agora);
        $motivo = $this->conteudo->motivoDoBloqueio($pasta, $tenant);

        return new LinksExternosDaPastaOutput(
            links: $saidas,
            podeGerar: $motivo === null,
            motivoBloqueio: $motivo,
            analisesCompartilhaveis: count($this->conteudo->analisesCompartilhaveis($pasta, $tenant)),
            ultimoAcesso: $this->ultimoAcesso($saidas),
        );
    }

    /** @param LinkExternoDoPushOutput[] $saidas */
    private function ultimoAcesso(array $saidas): ?\DateTimeImmutable
    {
        $ultimo = null;
        foreach ($saidas as $saida) {
            $quando = $saida->ultimoAcessoEm;
            if ($quando !== null && ($ultimo === null || $quando > $ultimo)) {
                $ultimo = $quando;
            }
        }

        return $ultimo;
    }
}
