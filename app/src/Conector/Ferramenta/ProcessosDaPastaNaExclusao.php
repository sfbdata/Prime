<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Processo\UseCase\ExcluirProcessoUseCase;
use App\Twig\NumeroProcessoExtension;

/**
 * `excluir_processo`: sempre proposta, sem volta (6.6). Apaga o processo do escritório inteiro, não só o vínculo com
 * esta pasta ({@see ExcluirProcessoUseCase}). Pede o recurso `processo` de excluir, como a tela (`processo_delete`):
 * quem só edita não exclui. A confirmação refaz todas as conferências.
 */
final class ProcessosDaPastaNaExclusao
{
    public function __construct(
        private readonly ProcessosDaPastaResolvedor $resolvedor,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PropostasDoConector $propostas,
        private readonly NumeroProcessoExtension $numero,
        private readonly ExcluirProcessoUseCase $excluir,
    ) {
    }

    public function propor(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $processo = $this->conferir($entrada, $pasta, $chamada);
        $resumo = sprintf(
            'Excluir de vez o processo %s do escritório, a partir da pasta %s. Ele sai de todas as pastas em que está vinculado, '
            . 'junto com partes, movimentações, documentos, notas técnicas e previsões. Não há como desfazer.',
            $this->numero->numeroCnj($processo->getNumeroProcesso()),
            $this->protecao->rotulo($chamada->contexto->escritorio, $pasta->getNup() ?? '', 'sem número'),
        );

        return $this->propostas->propor($chamada, ProcessosDaPastaFerramenta::NOME, $entrada->acao, $entrada->dados(), $resumo);
    }

    /** @throws ErroDaFerramenta */
    public function reconferir(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): void
    {
        $this->conferir($entrada, $pasta, $chamada);
    }

    public function confirmar(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $processo = $this->conferir($entrada, $pasta, $chamada);
        $processoId = (int) $processo->getId();
        ProcessosDaPastaRecusa::traduzindo(fn () => $this->excluir->executar($processo, $chamada->contexto->escritorio));

        return ProcessosDaPastaResposta::confirmada($pasta, $entrada, ['excluidos' => [['processo_id' => $processoId]]], ['processo' => [$processoId]]);
    }

    /** O processo é do escritório e desta pasta, não está em segredo, e a pessoa pode excluí-lo. */
    private function conferir(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): Processo
    {
        $processo = $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $chamada->contexto->escritorio, true);
        $this->resolvedor->exigirExclusaoDoProcesso($processo, $chamada);

        return $processo;
    }
}
