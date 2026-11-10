<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\RestaurarBatidaInput;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\PontoAjusteRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * O gestor devolve à conta a batida que desconsiderou (E10, plano-e10-ponto §6): o "Restaurar" da linha
 * "Desconsideradas" da ficha. A batida volta com o tipo e a data/hora de antes da desconsideração; o evento
 * `ajuste_desfeito` vai para a trilha do Ponto no mesmo COMMIT.
 *
 * Sob a trava da pessoa, a desconsideração tem de ser desta folha e ainda a cabeça da linhagem. A desconsideração
 * feita pela análise da justificativa (a volta do abono para análise, a recusa do trabalho externo) não se restaura
 * pela tela: só volta pela análise, com um novo abono (esquecimento) ou com a recusada do trabalho externo voltando
 * para análise (decisão A). Enquanto a justificativa ligada à batida (o trabalho externo da E12 ou o esquecimento da
 * inclusão) está em análise, o Restaurar também recusa (C5): quem decide é a análise. A própria batida só o primário
 * ou o Master restaura (P43). Recusado, nada é gravado.
 */
final class RestaurarBatidaUseCase
{
    public function __construct(
        private readonly TrilhaDoPonto $trilha,
        private readonly GravacaoDoAjusteDoPonto $gravacao,
        private readonly RegistroPontoRepository $registros,
        private readonly PontoAjusteRepository $ajustes,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
        private readonly JustificativaPontoRepository $justificativas,
    ) {
    }

    /**
     * @throws AjusteDeBatidaRecusadoException a própria batida (P43), desconsideração fora desta folha, outro ajuste
     *                                         depois dela, ligada à justificativa, justificativa em análise (C5) ou
     *                                         motivo vazio
     */
    public function executar(RestaurarBatidaInput $input): PontoAjuste
    {
        $this->exigirQuePodeCorrigir($input);

        return $this->trilha->emTransacao(fn (): PontoAjuste => $this->restaurarSobATrava($input));
    }

    private function exigirQuePodeCorrigir(RestaurarBatidaInput $input): void
    {
        if (!$this->analiseDaPropria->permite($input->autor, $input->pessoa, $input->escritorio)) {
            throw AjusteDeBatidaRecusadoException::daPropriaBatida();
        }
    }

    private function restaurarSobATrava(RestaurarBatidaInput $input): PontoAjuste
    {
        $this->gravacao->travar($input->pessoa);
        $desconsideracao = $this->desconsideracaoDaFolha($input);
        $alvo = $this->alvoAindaNaCabeca($input, $desconsideracao);
        if ($desconsideracao->getJustificativa() !== null || $desconsideracao->getDesfaz() !== null) {
            throw AjusteDeBatidaRecusadoException::ligadaAJustificativa();
        }
        if ($this->justificativas->temEmAnaliseLigadaA($alvo)) {
            throw AjusteDeBatidaRecusadoException::emAnalise();
        }

        return $this->gravacao->restaurar($desconsideracao, $input->motivo, $input->autor);
    }

    /** A desconsideração da pessoa NESTE escritório; outra coisa (ou de outra folha) não existe para quem pede. */
    private function desconsideracaoDaFolha(RestaurarBatidaInput $input): PontoAjuste
    {
        $ajuste = $this->ajustes->find($input->ajusteId);
        if (!$ajuste instanceof PontoAjuste
            || $ajuste->getTipo() !== TipoDoAjuste::Desconsideracao
            || $ajuste->getTenant()->getId() !== $input->escritorio->getId()
            || $ajuste->getUser()->getId() !== $input->pessoa->getId()) {
            throw AjusteDeBatidaRecusadoException::naoEncontrada();
        }

        return $ajuste;
    }

    /** A batida da desconsideração, que ninguém ajustou nem restaurou depois dela. */
    private function alvoAindaNaCabeca(RestaurarBatidaInput $input, PontoAjuste $desconsideracao): AlvoDoAjuste
    {
        $alvo = $this->registros->alvoDoAjuste($input->pessoa, $input->escritorio, $desconsideracao->alvo());
        if ($alvo === null || $alvo->cabecaId() !== $desconsideracao->getId()) {
            throw AjusteDeBatidaRecusadoException::mudou();
        }

        return $alvo;
    }
}
