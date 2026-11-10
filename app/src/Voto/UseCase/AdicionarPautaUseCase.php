<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\PautaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\OpcoesDaPauta;

/**
 * Inclui uma pauta na assembleia em rascunho, no fim da ordem, com as opções numeradas (padrão do condomínio: 1 Sim,
 * 2 Não) e a ABSTENÇÃO criada sozinha (número 0, não conta como voto válido).
 */
final class AdicionarPautaUseCase
{
    public const ROTULO_DA_ABSTENCAO = 'Abstenção';

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly OpcoesDaPauta $opcoesDaPauta,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, PautaInput $input): int
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $opcoes = $this->opcoesDaPauta->validar(trim($input->titulo), $input->opcoes);
        $pauta = new PautaDaAssembleia($assembleia, self::proximaOrdem($assembleia), $input->titulo, $input->descricaoLimpa());
        foreach ($opcoes as $opcao) {
            new OpcaoDaPauta($pauta, $opcao['numero'], $opcao['rotulo']);
        }
        new OpcaoDaPauta($pauta, 0, self::ROTULO_DA_ABSTENCAO, true);
        $this->pautas->salvar($pauta, flush: true);

        return (int) $pauta->getId();
    }

    private static function proximaOrdem(Assembleia $assembleia): int
    {
        $maior = 0;
        foreach ($assembleia->getPautas() as $pauta) {
            $maior = max($maior, $pauta->getOrdem());
        }

        return $maior + 1;
    }
}
