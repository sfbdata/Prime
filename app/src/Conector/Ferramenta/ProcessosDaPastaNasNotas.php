<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\UseCase\CriarNotaTecnicaUseCase;
use App\Processo\UseCase\EditarNotaTecnicaUseCase;
use App\Processo\UseCase\ExcluirNotaTecnicaUseCase;
use App\Twig\NumeroProcessoExtension;

/**
 * `nota_tecnica`: criar é direto (menção avisa o colega, pela pasta); editar e excluir são proposta (6.6). Quem pode
 * editar ou excluir é decisão dos UseCases da tela (autor na janela de 15 minutos, ou o poder sem prazo): a ferramenta
 * pergunta a eles antes de propor e a confirmação pergunta de novo.
 */
final class ProcessosDaPastaNasNotas
{
    public const NAO_EDITAVEL = 'Esta nota técnica não pode ser editada.';
    public const NAO_EXCLUIVEL = 'Esta nota técnica não pode ser excluída.';

    private const TRECHO = 200;

    public function __construct(
        private readonly ProcessosDaPastaResolvedor $resolvedor,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PropostasDoConector $propostas,
        private readonly NumeroProcessoExtension $numero,
        private readonly CriarNotaTecnicaUseCase $criar,
        private readonly EditarNotaTecnicaUseCase $editar,
        private readonly ExcluirNotaTecnicaUseCase $excluir,
    ) {
    }

    public function executar(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $contexto = $chamada->contexto;
        $processo = $this->processo($entrada, $pasta, $chamada);
        if ($entrada->operacao === 'criar') {
            $nota = ProcessosDaPastaRecusa::traduzindo(fn () => $this->criar->executar($processo, $contexto->pessoa, $contexto->escritorio, $entrada->texto('conteudo'), null, $pasta));

            return ProcessosDaPastaResposta::direta($pasta, $entrada, ['processo_id' => (int) $processo->getId(), 'nota_id' => $nota->id], ['processo' => [(int) $processo->getId()], 'nota' => [$nota->id]]);
        }
        $nota = $this->resolvedor->nota($entrada->id('nota_id'), $processo, $contexto->escritorio);
        $this->exigirPodeMexer($entrada, $nota, $chamada);

        return $this->propor($entrada, $pasta, $processo, $nota, $chamada);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws \App\Conector\Exception\ErroDaFerramenta
     */
    public function reconferir(ProcessosDaPastaEntrada $entrada, array $dados, Pasta $pasta, ChamadaDoConector $chamada): void
    {
        $processo = $this->processo($entrada, $pasta, $chamada);
        $nota = $this->resolvedor->nota($entrada->id('nota_id'), $processo, $chamada->contexto->escritorio);
        $this->exigirPodeMexer($entrada, $nota, $chamada);
        if ($entrada->operacao === 'editar' && ($dados['anterior']['conteudo_hash'] ?? null) !== self::hash($nota)) {
            throw ProcessosDaPastaRecusa::mudou();
        }
    }

    public function confirmar(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $contexto = $chamada->contexto;
        $processo = $this->processo($entrada, $pasta, $chamada);
        $nota = $this->resolvedor->nota($entrada->id('nota_id'), $processo, $contexto->escritorio);
        $notaId = (int) $nota->getId();
        ProcessosDaPastaRecusa::traduzindo(fn () => $entrada->operacao === 'editar'
            ? $this->editar->executar($nota, $contexto->pessoa, $contexto->escritorio, $entrada->texto('conteudo'), $pasta)
            : $this->excluir->executar($nota, $contexto->pessoa, $contexto->escritorio));
        $extras = ['processo_id' => (int) $processo->getId()] + ($entrada->operacao === 'editar' ? ['nota_id' => $notaId] : ['excluidos' => [['nota_id' => $notaId]]]);

        return ProcessosDaPastaResposta::confirmada($pasta, $entrada, $extras, ['processo' => [(int) $processo->getId()], 'nota' => [$notaId]]);
    }

    private function processo(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): Processo
    {
        $processo = $this->resolvedor->processo($entrada->id('processo_id'), $pasta, $chamada->contexto->escritorio, true);
        $this->resolvedor->exigirEdicaoDoProcesso($processo, $chamada);

        return $processo;
    }

    private function exigirPodeMexer(ProcessosDaPastaEntrada $entrada, NotaTecnica $nota, ChamadaDoConector $chamada): void
    {
        $contexto = $chamada->contexto;
        $pode = $entrada->operacao === 'editar'
            ? $this->editar->podeEditar($nota, $contexto->pessoa, $contexto->escritorio)
            : $this->excluir->podeExcluir($nota, $contexto->pessoa, $contexto->escritorio);
        if (!$pode) {
            throw ProcessosDaPastaRecusa::recusa($entrada->operacao === 'editar' ? self::NAO_EDITAVEL : self::NAO_EXCLUIVEL);
        }
    }

    private function propor(ProcessosDaPastaEntrada $entrada, Pasta $pasta, Processo $processo, NotaTecnica $nota, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $onde = sprintf('do processo %s (pasta %s)', $this->numero->numeroCnj($processo->getNumeroProcesso()), $this->protecao->rotulo($escritorio, $pasta->getNup() ?? '', 'sem número'));
        $atual = $this->trecho($escritorio, $nota->getConteudo());
        if ($entrada->operacao === 'excluir') {
            $resumo = sprintf('Excluir a nota técnica "%s" %s. Não há como desfazer.', $atual, $onde);

            return $this->propostas->propor($chamada, ProcessosDaPastaFerramenta::NOME, $entrada->acao, $entrada->dados(), $resumo);
        }
        $resumo = sprintf('Editar a nota técnica %s, por cima do que está escrito: de "%s" para "%s".', $onde, $atual, $this->trecho($escritorio, $entrada->texto('conteudo')));

        return $this->propostas->propor($chamada, ProcessosDaPastaFerramenta::NOME, $entrada->acao, $entrada->dados(['conteudo_hash' => self::hash($nota)]), $resumo);
    }

    private function trecho(Tenant $escritorio, string $html): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));

        return $this->protecao->rotulo($escritorio, mb_substr($texto, 0, self::TRECHO) . (mb_strlen($texto) > self::TRECHO ? '…' : ''), 'sem texto');
    }

    private static function hash(NotaTecnica $nota): string
    {
        return hash('sha256', $nota->getConteudo());
    }
}
