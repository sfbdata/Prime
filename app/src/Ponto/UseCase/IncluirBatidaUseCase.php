<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\IncluirBatidaInput;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * O gestor inclui na folha a batida que falta (E10, plano-e10-ponto §6): o "+" e o "Adicionar Batida" da ficha do
 * colaborador. A batida entra como ajuste de inclusão (o alvo dela é 'a{id}'), com o motivo e o evento
 * `batida_incluida` na trilha do Ponto, no mesmo COMMIT. Nada entra em `registro_ponto`, que guarda só o que o
 * colaborador bateu.
 *
 * A própria batida só o primário do escritório ou o Master inclui (P43, decisão B: a inclusão é um ajuste). Recusado,
 * nada é gravado.
 */
final class IncluirBatidaUseCase
{
    public function __construct(
        private readonly TrilhaDoPonto $trilha,
        private readonly GravacaoDoAjusteDoPonto $gravacao,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
    ) {
    }

    /** @throws AjusteDeBatidaRecusadoException a própria batida (P43), motivo vazio ou horário futuro */
    public function executar(IncluirBatidaInput $input): PontoAjuste
    {
        $this->exigirQuePodeCorrigir($input);

        return $this->trilha->emTransacao(fn (): PontoAjuste => $this->incluirSobATrava($input));
    }

    private function exigirQuePodeCorrigir(IncluirBatidaInput $input): void
    {
        if (!$this->analiseDaPropria->permite($input->autor, $input->pessoa, $input->escritorio)) {
            throw AjusteDeBatidaRecusadoException::daPropriaBatida();
        }
    }

    private function incluirSobATrava(IncluirBatidaInput $input): PontoAjuste
    {
        $this->gravacao->travar($input->pessoa);

        return $this->gravacao->incluir($input->escritorio, $input->pessoa, $input->tipo, $input->dataHora, $input->motivo, $input->autor, null);
    }
}
