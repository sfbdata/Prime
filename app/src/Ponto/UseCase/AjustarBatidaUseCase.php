<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\AjustarBatidaInput;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * O gestor muda o tipo, a data ou a hora de uma batida da folha (E10, plano-e10-ponto §6): a página "Ajustar batida"
 * da ficha do colaborador. O registro original fica como foi gravado; a correção entra como ajuste preso à cabeça da
 * linhagem, com o evento `batida_ajustada` na trilha do Ponto, no mesmo COMMIT.
 *
 * Sob a trava da pessoa, a batida é lida de novo e a cabeça dela tem de ser a que a tela mostrou: outro ajuste no meio
 * tempo recusa (o gestor confere a folha e tenta de novo). A própria batida só o primário ou o Master corrige (P43).
 * Recusado, nada é gravado.
 */
final class AjustarBatidaUseCase
{
    public function __construct(
        private readonly TrilhaDoPonto $trilha,
        private readonly GravacaoDoAjusteDoPonto $gravacao,
        private readonly RegistroPontoRepository $registros,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
    ) {
    }

    /**
     * @throws AjusteDeBatidaRecusadoException a própria batida (P43), batida fora desta folha, outro ajuste no meio,
     *                                         batida fora da conta, motivo vazio, horário futuro ou nada a mudar
     */
    public function executar(AjustarBatidaInput $input): PontoAjuste
    {
        $this->exigirQuePodeCorrigir($input);

        return $this->trilha->emTransacao(fn (): PontoAjuste => $this->ajustarSobATrava($input));
    }

    private function exigirQuePodeCorrigir(AjustarBatidaInput $input): void
    {
        if (!$this->analiseDaPropria->permite($input->autor, $input->pessoa, $input->escritorio)) {
            throw AjusteDeBatidaRecusadoException::daPropriaBatida();
        }
    }

    private function ajustarSobATrava(AjustarBatidaInput $input): PontoAjuste
    {
        $this->gravacao->travar($input->pessoa);
        $alvo = $this->alvoComACabecaQueATelaMostrou($input);

        return $this->gravacao->alterar($alvo, $input->tipo, $input->dataHora, $input->motivo, $input->autor);
    }

    private function alvoComACabecaQueATelaMostrou(AjustarBatidaInput $input): AlvoDoAjuste
    {
        $alvo = $this->registros->alvoDoAjuste($input->pessoa, $input->escritorio, $input->alvo)
            ?? throw AjusteDeBatidaRecusadoException::naoEncontrada();
        if ($alvo->cabecaId() !== $input->cabecaEsperada) {
            throw AjusteDeBatidaRecusadoException::mudou();
        }

        return $alvo;
    }
}
