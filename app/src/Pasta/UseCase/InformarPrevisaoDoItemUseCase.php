<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaChecklistCobranca;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;

/**
 * O responsável informa até que dia resolve um documento cobrado (desenho 02, `ckPrevisao`, dc 4309-4313):
 * a cobrança pausa até a data e no dia seguinte o sistema confere de novo.
 *
 * ISOLADO de propósito: o desenho recebe a data "no Chat I.A", e como ela entra na tela está com o dono
 * (spec, decisão 4). A resposta pelo Chat depende das frentes de Inteligência (4b) e do "Encaminhar via
 * Chat I.A" (5d); quando existir, chama este UseCase. Só o responsável da pasta informa; data no passado é
 * recusada.
 */
final class InformarPrevisaoDoItemUseCase
{
    public function __construct(
        private readonly PastaChecklistCobrancaRepository $cobrancas,
    ) {
    }

    public function executar(PastaChecklistItem $item, User $quem, \DateTimeImmutable $data, \DateTimeImmutable $agora): void
    {
        $pasta = $item->getPasta();
        if ($pasta === null || $pasta->getResponsavel()?->getId() !== $quem->getId()) {
            throw new \DomainException('Só o responsável da pasta informa a previsão.');
        }
        if ($item->isConcluido()) {
            throw new \DomainException('Este documento já foi resolvido.');
        }
        if ($data->format('Y-m-d') < $agora->format('Y-m-d')) {
            throw new \DomainException('Informe uma data de hoje em diante.');
        }

        $registro = $this->cobrancas->doItem($item) ?? new PastaChecklistCobranca($item->getTenant() ?? $pasta->getTenant(), $item, $agora->setTime(0, 0));
        $registro->informarPrevisao($data->setTime(0, 0), $agora);
        $this->cobrancas->salvar($registro, flush: true);
    }
}
