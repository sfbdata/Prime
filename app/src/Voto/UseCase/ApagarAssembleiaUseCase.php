<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Apaga para sempre a votação que não está ativa (sem pauta aberta, suspensa ou programada para abrir: teste, arquivada, nunca aberta ou encerrada): votos,
 * unidades, candidaturas, edital e ata saem juntos, pelo ON DELETE CASCADE do banco, como na simulação. Quem confirma
 * digita o nome da assembleia ou APAGAR; sem isso nada é apagado. O `audit_log` registra a exclusão (a assembleia é
 * `Auditavel`). As fotos e o edital só saem do armazenamento depois do COMMIT.
 */
final class ApagarAssembleiaUseCase
{
    public const PALAVRA_DE_CONFIRMACAO = 'APAGAR';

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly FotosDasCandidaturas $fotos,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $confirmacao): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $confirmacao): void {
            $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
                ?? throw new AssembleiaNaoEncontradaException();
            $assembleia->exigirApagavel();
            $digitado = mb_strtolower(trim($confirmacao));
            if ('' === $digitado || ($digitado !== mb_strtolower(self::PALAVRA_DE_CONFIRMACAO) && $digitado !== mb_strtolower(trim($assembleia->getTitulo())))) {
                throw new OperacaoInvalidaNaAssembleiaException('Para apagar, digite o nome da assembleia ou ' . self::PALAVRA_DE_CONFIRMACAO . '.');
            }
            $fotos = [];
            foreach ($assembleia->getPautas() as $pauta) {
                $fotos = [...$fotos, ...FotosDasCandidaturas::chavesDaPauta($pauta)];
            }
            if ($assembleia->temEdital()) {
                $fotos[] = ChavesDoVoto::edital($assembleia);
            }
            $this->assembleias->remover($assembleia, flush: true);
            $this->fotos->descartar($fotos, 'votação apagada');
        });
    }
}
