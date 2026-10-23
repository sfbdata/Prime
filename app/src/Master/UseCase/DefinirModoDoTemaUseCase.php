<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\DefinirModoDoTemaInput;
use App\Master\Entity\TemaConfig;
use App\Master\Exception\TemaRecusado;
use App\Master\Repository\TemaConfigRepository;
use App\Master\Service\CatalogoDeTemas;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Troca o modo dos temas comemorativos e o tema fixado (spec docs/specs/temas-comemorativos.md, "Controle").
 *
 * Quem dispara: o Master, no card "Temas comemorativos" da aba "Telas e recursos" de /master (o controller exige
 * ROLE_SUPER_ADMIN).
 * Objetivo: deixar o tema pelo calendário, fixar um tema à mão ou desligar os temas para toda a plataforma.
 * Pré-condições: o modo é automatico, manual ou desligado; o tema fixado, quando houver, existe no catálogo.
 * Fluxo principal: carrega a linha única (ou cria), grava modo e tema fixado, devolve a configuração.
 * Fluxos alternativos: modo inválido ou tema desconhecido são recusados (422 no controller); pedido igual ao que
 * já vale não grava nada, para a trilha não ganhar uma alteração que não mudou coisa alguma.
 * Pós-condições: todas as páginas logadas passam a ler o tema novo.
 * Auditoria: TemaConfig é Auditavel; o flush roda no EscopoDeAuditoriaDePlataforma (efeito em todas as contas,
 * a linha fica sem escritório e aparece só na trilha do Master), como o AlternarRecursoUseCase.
 */
final class DefinirModoDoTemaUseCase
{
    public function __construct(
        private readonly TemaConfigRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(DefinirModoDoTemaInput $input): TemaConfig
    {
        if (!in_array($input->modo, TemaConfig::MODOS, true)) {
            throw new TemaRecusado('Modo de tema inválido.');
        }

        if ($input->manual !== null && !CatalogoDeTemas::existe($input->manual)) {
            throw new TemaRecusado('Tema desconhecido.');
        }

        $existente = $this->repositorio->buscarUnica();
        if ($existente !== null && $existente->getModo() === $input->modo && $existente->getManual() === $input->manual) {
            return $existente;
        }

        if ($existente === null && $input->modo === TemaConfig::MODO_AUTOMATICO && $input->manual === null) {
            // Sem linha, já vale o automático sem tema fixado.
            return new TemaConfig();
        }

        $config = $existente ?? new TemaConfig();
        $config->definirModo($input->modo, $input->manual, $input->ator, $this->relogio->now());
        $this->escopoDePlataforma->executar(fn () => $this->repositorio->salvar($config, flush: true));

        return $config;
    }
}
