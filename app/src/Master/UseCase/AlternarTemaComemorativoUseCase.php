<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AlternarTemaInput;
use App\Master\Entity\TemaConfig;
use App\Master\Exception\TemaRecusado;
use App\Master\Repository\TemaConfigRepository;
use App\Master\Service\CatalogoDeTemas;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Liga ou desliga um tema comemorativo no modo automático (spec docs/specs/temas-comemorativos.md, "Controle").
 *
 * Quem dispara: o Master, no interruptor de cada tema do card "Temas comemorativos" (controller com ROLE_SUPER_ADMIN).
 * Objetivo: tirar uma data do calendário (por exemplo, não pintar o sistema no Outubro Rosa) sem desligar os outros.
 * Pré-condições: o tema existe no catálogo.
 * Fluxo principal: carrega a linha única (ou cria), tira ou põe o id na lista de desligados, grava e devolve.
 * Fluxos alternativos: tema desconhecido é recusado (422 no controller); pedir o estado que já vale não grava nada.
 * Pós-condições: o automático passa a ignorar (ou a considerar) o tema. O manual não olha a lista.
 * Auditoria: como o {@see DefinirModoDoTemaUseCase}, no EscopoDeAuditoriaDePlataforma.
 */
final class AlternarTemaComemorativoUseCase
{
    public function __construct(
        private readonly TemaConfigRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AlternarTemaInput $input): TemaConfig
    {
        if (!CatalogoDeTemas::existe($input->id)) {
            throw new TemaRecusado('Tema desconhecido.');
        }

        $existente = $this->repositorio->buscarUnica();
        if ($existente !== null && $existente->estaLigado($input->id) === $input->ligado) {
            return $existente;
        }

        if ($existente === null && $input->ligado) {
            // Sem linha, todos já estão ligados.
            return new TemaConfig();
        }

        $config = $existente ?? new TemaConfig();
        $config->ligarTema($input->id, $input->ligado, $input->ator, $this->relogio->now());
        $this->escopoDePlataforma->executar(fn () => $this->repositorio->salvar($config, flush: true));

        return $config;
    }
}
