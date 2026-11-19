<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Pasta\UseCase\DefinirProcessoPrincipalUseCase;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Pasta\UseCase\VincularApensadoUseCase;
use App\Pasta\UseCase\VincularProcessoUseCase;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\UseCase\CadastrarProcessoPelaPastaUseCase;
use App\Twig\NumeroProcessoExtension;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Os vínculos de processo da `processos_da_pasta`: `vincular` (novo, cadastrado ou apensado; direto, e recusa em pasta
 * cujo cliente principal recebe o envio automático), `principal` (direto) e `vincular` com `desvincular` (proposta: o
 * processo continua existindo, mas sai da pasta).
 */
final class ProcessosDaPastaNosVinculos
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProcessosDaPastaResolvedor $resolvedor,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PropostasDoConector $propostas,
        private readonly NumeroProcessoExtension $numero,
        private readonly CadastrarProcessoPelaPastaUseCase $cadastrar,
        private readonly VincularProcessoUseCase $vincularProcesso,
        private readonly VincularApensadoUseCase $vincularApensado,
        private readonly DefinirProcessoPrincipalUseCase $definirPrincipal,
        private readonly DesvincularProcessoUseCase $desvincularProcesso,
    ) {
    }

    public function vincular(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        if ($entrada->operacao === 'desvincular') {
            return $this->proporDesvinculo($entrada, $pasta, $chamada);
        }
        // Antes de tudo: a chamada inteira recusa, sem criar processo nem gravar nada.
        $this->resolvedor->exigirSemEnvioAutomatico($pasta, $escritorio);

        $processo = $entrada->operacao === 'novo'
            ? $this->cadastrado($entrada, $chamada)
            : $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $escritorio, false);
        $jaEstava = $pasta->temProcesso($processo);
        $novoSemId = $processo->getId() === null;
        try {
            $this->ligar($entrada, $pasta, $processo, $chamada);
        } catch (\Throwable $e) {
            // O processo novo só está persistido (o flush é do vínculo): se o vínculo recusa, ele sai do EntityManager
            // e nenhum flush posterior o grava. O que o vínculo pode recusar (cadeado, escritório) só existe em
            // processo que já era do escritório, e esse já estava no banco.
            if ($novoSemId && $this->em->contains($processo)) {
                $this->em->detach($processo);
            }

            throw $e;
        }

        return ProcessosDaPastaResposta::direta($pasta, $entrada, ['processo_id' => (int) $processo->getId(), 'ja_estava_vinculado' => $jaEstava], ['processo' => [(int) $processo->getId()]]);
    }

    private function ligar(ProcessosDaPastaEntrada $entrada, Pasta $pasta, Processo $processo, ChamadaDoConector $chamada): void
    {
        $pessoa = $chamada->contexto->pessoa;
        ProcessosDaPastaRecusa::traduzindo(fn () => $entrada->operacao === 'apensado'
            ? $this->vincularApensado->executar($pasta, $processo, TipoDeApenso::from($entrada->texto('tipo_apenso')), $pessoa)
            : $this->vincularProcesso->executar($pasta, $processo, $pessoa));
    }

    public function principal(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $processo = $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $chamada->contexto->escritorio, true);
        ProcessosDaPastaRecusa::traduzindo(fn () => $this->definirPrincipal->executar($pasta, $processo));

        return ProcessosDaPastaResposta::direta($pasta, $entrada, ['processo_id' => (int) $processo->getId()], ['processo' => [(int) $processo->getId()]]);
    }

    /** Sem mudar nada na proposta: o `reconferir` só olha que o processo ainda é da pasta e que não entrou em segredo. */
    public function reconferirDesvinculo(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): void
    {
        $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $chamada->contexto->escritorio, true);
    }

    public function desvincular(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $processo = $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $chamada->contexto->escritorio, true);
        ProcessosDaPastaRecusa::traduzindo(fn () => $this->desvincularProcesso->executar($pasta, $processo, $chamada->contexto->pessoa));

        return ProcessosDaPastaResposta::confirmada($pasta, $entrada, ['desvinculados' => [['processo_id' => (int) $processo->getId()]]], ['processo' => [(int) $processo->getId()]]);
    }

    private function proporDesvinculo(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $processo = $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $escritorio, true);
        $resumo = sprintf(
            'Desvincular o processo %s da pasta %s. O processo continua cadastrado no escritório e em outras pastas.',
            $this->numero->numeroCnj($processo->getNumeroProcesso()),
            $this->protecao->rotulo($escritorio, $pasta->getNup() ?? '', 'sem número'),
        );

        return $this->propostas->propor($chamada, ProcessosDaPastaFerramenta::NOME, $entrada->acao, $entrada->dados(), $resumo);
    }

    /**
     * O processo novo, do cadastro pela janela (sem a consulta do CNJ, que só existe na sessão da tela). Número que já é
     * do escritório devolve o existente, sem aplicar o que veio no pedido (e, se em segredo, recusa).
     *
     * @throws ErroDaFerramenta
     */
    private function cadastrado(ProcessosDaPastaEntrada $entrada, ChamadaDoConector $chamada): Processo
    {
        $campos = $entrada->campos;
        $input = new CadastroDoProcessoInput(
            numeroProcesso: $entrada->texto('numero_processo'),
            orgaoJulgador: $entrada->texto('orgao_julgador'),
            classeProcessual: $entrada->texto('classe_processual'),
            assuntoProcessual: $entrada->texto('assunto_processual'),
            situacaoProcesso: (string) ($campos['situacao_processo'] ?? ''),
            instancia: (string) ($campos['instancia'] ?? ''),
            dataDistribuicao: (string) ($campos['data_distribuicao'] ?? ''),
        );
        $cadastro = ProcessosDaPastaRecusa::traduzindo(fn () => $this->cadastrar->executar($input, $chamada->contexto->escritorio, $chamada->contexto->pessoa));
        if ($cadastro->processo->emSegredoDeJustica()) {
            throw ErroDaFerramenta::emSegredo();
        }

        return $cadastro->processo;
    }
}
