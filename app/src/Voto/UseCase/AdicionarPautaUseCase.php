<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\PautaInput;
use App\Voto\DTO\RegrasDaPautaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\OpcoesDaPauta;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Inclui uma pauta na assembleia em rascunho, no fim da ordem, com as opções numeradas (padrão do condomínio: 1 Sim,
 * 2 Não; regras padrão se a tela não manda) e a ABSTENÇÃO criada sozinha (número 0, não conta como voto válido).
 */
final class AdicionarPautaUseCase
{
    public const ROTULO_DA_ABSTENCAO = 'Abstenção';

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly OpcoesDaPauta $opcoesDaPauta,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, PautaInput $input): int
    {
        return $this->serializacao->executar($tenant, $assembleiaId, fn (): int => $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $input));
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, PautaInput $input): int
    {
        $pauta = $this->montar($tenant, $assembleiaId, $input);
        $this->pautas->salvar($pauta, flush: true);

        return (int) $pauta->getId();
    }

    /**
     * Monta a pauta (validação, ordem, opções e abstenção) e a deixa pendente na assembleia, sem trava e sem gravar.
     * Quem chama é dono da trava de configuração e da transação (AplicarModeloDePautasUseCase grava o modelo inteiro de uma vez).
     */
    public function montar(Tenant $tenant, int $assembleiaId, PautaInput $input): PautaDaAssembleia
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $regras = $input->regras ?? new RegrasDaPautaInput();
        $opcoes = $this->opcoesDaPauta->validar(trim($input->titulo), $input->opcoes, $regras->digitos, $input->tipo->eleitoral());
        $pauta = new PautaDaAssembleia($assembleia, self::proximaOrdem($assembleia), $input->titulo, $input->descricaoLimpa(), $input->tipo);
        $pauta->configurar($regras->criterio, $regras->base, $regras->digitos, $regras->vagas, $regras->quorumPrimeira, $regras->quorumSegunda);
        foreach ($opcoes as $opcao) {
            (new OpcaoDaPauta($pauta, $opcao['numero'], $opcao['rotulo']))->detalhar($opcao['descricao'], $opcao['propostas'], $opcao['composicao'], $opcao['unidade']);
        }
        new OpcaoDaPauta($pauta, 0, self::ROTULO_DA_ABSTENCAO, true);

        return $pauta;
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
