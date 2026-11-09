<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\DocumentosDaSelecao;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * "Excluir" da lista: leva para a Lixeira (desenho). O que aguardava assinatura tem o envio cancelado ("Envio
 * cancelado pela exclusão. Links desativados") e todo documento movido registra "Movido para a Lixeira. Evidências
 * preservadas". O que já estava na Lixeira fica como está.
 */
final readonly class MoverParaLixeiraUseCase
{
    public function __construct(
        private DocumentosDaSelecao $selecao,
        private DocumentoParaAssinarRepository $documentos,
        private TrilhaDoDocumento $trilha,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @throws DocumentoNaoEncontrado
     */
    public function executar(User $ator, Tenant $tenant, array $ids): void
    {
        $documentos = $this->selecao->carregar($ids, $tenant);
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $this->documentos->emTransacao(function () use ($documentos, $ator, $agora): void {
            foreach ($documentos as $documento) {
                if ($documento->estaNaLixeira()) {
                    continue;
                }

                if ($documento->moverParaLixeira($ator, $agora)) {
                    $this->trilha->acrescentar($documento, TipoDeEvento::EnvioCanceladoPelaExclusao, [], $ator);
                }
                $this->trilha->acrescentar($documento, TipoDeEvento::MovidoParaLixeira, [], $ator);
            }
        });
    }
}
