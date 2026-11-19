<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Service\PastaDoContextoDaIa;
use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\DefinirPastaAdministrativaUseCase;
use App\Pasta\UseCase\EditarAcaoDaPastaUseCase;

/**
 * `processos_da_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 13). A `acao` é a chave da {@see TabelaDeAbaEChave};
 * a ação fina de `vincular` e de `nota_tecnica` vai em `operacao`:
 *
 *  - `vincular` (`novo`, `cadastrado`, `apensado`): direto, recusa em pasta cujo cliente principal recebe o envio
 *    automático do Push ({@see ProcessosDaPastaNosVinculos}); `desvincular` é proposta;
 *  - `principal`: direto ({@see \App\Pasta\UseCase\DefinirProcessoPrincipalUseCase});
 *  - `dados_do_cartao`: direto com campo vazio; trocar valor manual já preenchido é proposta ({@see ProcessosDaPastaNoCartao});
 *  - `acao_da_pasta`: direto com a ação vazia; trocar a ação já preenchida é proposta ({@see EditarAcaoDaPastaUseCase});
 *  - `via_administrativa`: direto ({@see DefinirPastaAdministrativaUseCase}; troca a trilha do andamento);
 *  - `nota_tecnica`: `criar` direto; `editar` e `excluir` são proposta ({@see ProcessosDaPastaNasNotas});
 *  - `excluir_processo`: sempre proposta, sem volta, pede o recurso `processo` de excluir ({@see ProcessosDaPastaNaExclusao}).
 *
 * Ordem das recusas: escopo de escrita → ação e campos → chave do Master e módulo → a pasta por id e escritório (alheia
 * ou na lixeira: "não encontrado") → a guarda com a pasta (cadeado e aba Processo) → recurso `pasta` editar → segredo de
 * justiça da pasta → o que cada ação confere (processo, segredo do processo, envio automático). {@see reconferir()}
 * repete tudo, sem gravar. Até propor, a ferramenta só lê: o `propor()` dá `flush`.
 */
final class ProcessosDaPastaFerramenta implements FerramentaDoConector, ExecutorDeProposta
{
    /**
     * As duas interfaces declaram `TAG`; sem redeclarar aqui o PHP recusa a classe (constante ambígua). Quem registra
     * a ferramenta e o executor são os atributos `AutoconfigureTag` de cada interface, não esta constante.
     */
    public const TAG = FerramentaDoConector::TAG;

    public const NOME = 'processos_da_pasta';

    public const MOTIVO_MUDOU = ProcessosDaPastaRecusa::MOTIVO_MUDOU;
    public const MUDOU = ProcessosDaPastaRecusa::MUDOU;

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly PastaRepository $pastas,
        private readonly PastaDoContextoDaIa $liberacao,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PropostasDoConector $propostas,
        private readonly ProcessosDaPastaNosVinculos $vinculos,
        private readonly ProcessosDaPastaNoCartao $cartao,
        private readonly ProcessosDaPastaNasNotas $notas,
        private readonly ProcessosDaPastaNaExclusao $exclusao,
        private readonly EditarAcaoDaPastaUseCase $editarAcao,
        private readonly DefinirPastaAdministrativaUseCase $definirVia,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Processos da pasta';
    }

    public function descricao(): string
    {
        return ProcessosDaPastaEsquema::descricao();
    }

    public function esquemaDeEntrada(): array
    {
        return ProcessosDaPastaEsquema::esquema();
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        // Segunda camada: a `ScopePolicy` já deu 403 antes do SDK a quem não tem `mcp:escrita` (5.1).
        if (!$chamada->temEscopo(ServidorDeAutorizacao::ESCOPO_ESCRITA)) {
            throw ErroDaFerramenta::semPermissao();
        }
        $entrada = ProcessosDaPastaEntrada::dosArgumentos($argumentos);
        $this->guarda->exigir($chamada, self::NOME, $entrada->acao);
        $pasta = $this->conferirPasta($entrada, $chamada);

        return match ($entrada->acao) {
            'vincular' => $this->vinculos->vincular($entrada, $pasta, $chamada),
            'principal' => $this->vinculos->principal($entrada, $pasta, $chamada),
            'dados_do_cartao' => $this->cartao->executar($entrada, $pasta, $chamada),
            'acao_da_pasta' => $this->acaoDaPasta($entrada, $pasta, $chamada),
            'via_administrativa' => $this->viaAdministrativa($entrada, $pasta, $chamada),
            'excluir_processo' => $this->exclusao->propor($entrada, $pasta, $chamada),
            default => $this->notas->executar($entrada, $pasta, $chamada),
        };
    }

    public function ferramenta(): string
    {
        return self::NOME;
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $entrada = ProcessosDaPastaEntrada::dosDados($acao, $dados);
        $pasta = $this->conferirPasta($entrada, $chamada);

        match ($entrada->chave()) {
            'vincular.desvincular' => $this->vinculos->reconferirDesvinculo($entrada, $pasta, $chamada),
            'dados_do_cartao' => $this->cartao->reconferir($entrada, $dados, $pasta, $chamada),
            'acao_da_pasta' => $this->reconferirAcao($dados, $pasta),
            'nota_tecnica.editar', 'nota_tecnica.excluir' => $this->notas->reconferir($entrada, $dados, $pasta, $chamada),
            'excluir_processo' => $this->exclusao->reconferir($entrada, $pasta, $chamada),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $entrada = ProcessosDaPastaEntrada::dosDados($acao, $dados);
        $pasta = $this->pastas->findOneBy(['id' => $entrada->pastaId, 'tenant' => $chamada->contexto->escritorio]);
        if (!$pasta instanceof Pasta || $pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return match ($entrada->chave()) {
            'vincular.desvincular' => $this->vinculos->desvincular($entrada, $pasta, $chamada),
            'dados_do_cartao' => $this->cartao->confirmar($entrada, $pasta, $chamada),
            'acao_da_pasta' => $this->gravarAcao($entrada, $pasta, $chamada),
            'nota_tecnica.editar', 'nota_tecnica.excluir' => $this->notas->confirmar($entrada, $pasta, $chamada),
            'excluir_processo' => $this->exclusao->confirmar($entrada, $pasta, $chamada),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    /**
     * Tudo o que a ferramenta confere da pasta, na ordem da classe, e devolve a pasta viva da pessoa.
     *
     * @throws ErroDaFerramenta
     */
    private function conferirPasta(ProcessosDaPastaEntrada $entrada, ChamadaDoConector $chamada): Pasta
    {
        $escritorio = $chamada->contexto->escritorio;
        $pasta = $this->pastas->findOneBy(['id' => $entrada->pastaId, 'tenant' => $escritorio]);
        if (!$pasta instanceof Pasta || $pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->guarda->exigir($chamada, self::NOME, $entrada->acao, $pasta);
        $this->guarda->exigirEdicaoDaPasta($chamada, $pasta);
        try {
            $this->liberacao->liberar($chamada->contexto->pessoa, $escritorio, $entrada->pastaId);
        } catch (PastaDaIaNaoEncontradaException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (ContextoBloqueadoException) {
            throw ErroDaFerramenta::emSegredo();
        }

        return $pasta;
    }

    private function acaoDaPasta(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $atual = self::acaoAtual($pasta);
        $nova = mb_strtoupper($entrada->texto('nome_da_acao'));
        if ($atual === '' || $atual === $nova) {
            $mudou = $this->gravandoAcao($entrada, $pasta, $chamada);

            return ProcessosDaPastaResposta::direta($pasta, $entrada, ['mudou' => $mudou]);
        }
        $resumo = sprintf(
            'Trocar a ação da pasta %s: de "%s" para "%s".',
            $this->protecao->rotulo($escritorio, $pasta->getNup() ?? '', 'sem número'),
            $this->protecao->rotulo($escritorio, $atual, 'vazio'),
            $this->protecao->rotulo($escritorio, $nova, 'vazio'),
        );

        return $this->propostas->propor($chamada, self::NOME, $entrada->acao, $entrada->dados(['nome_da_acao' => $atual]), $resumo);
    }

    /** @param array<string, mixed> $dados */
    private function reconferirAcao(array $dados, Pasta $pasta): void
    {
        if (($dados['anterior']['nome_da_acao'] ?? null) !== self::acaoAtual($pasta)) {
            throw ProcessosDaPastaRecusa::mudou();
        }
    }

    private function gravarAcao(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $mudou = $this->gravandoAcao($entrada, $pasta, $chamada);

        return ProcessosDaPastaResposta::confirmada($pasta, $entrada, ['mudou' => $mudou]);
    }

    private function gravandoAcao(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): bool
    {
        $contexto = $chamada->contexto;

        return ProcessosDaPastaRecusa::traduzindo(fn () => $this->editarAcao->executar($pasta, $entrada->texto('nome_da_acao'), $contexto->pessoa, $contexto->escritorio));
    }

    private function viaAdministrativa(ProcessosDaPastaEntrada $entrada, Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $contexto = $chamada->contexto;
        $administrativa = (bool) ($entrada->campos['administrativa'] ?? false);
        $mudou = ProcessosDaPastaRecusa::traduzindo(fn () => $this->definirVia->executar($pasta, $administrativa, $contexto->pessoa, $contexto->escritorio));

        return ProcessosDaPastaResposta::direta($pasta, $entrada, ['administrativa' => $administrativa, 'mudou' => $mudou, 'trilha_do_andamento' => $administrativa ? 'administrativa' : 'judicial']);
    }

    private static function acaoAtual(Pasta $pasta): string
    {
        return mb_strtoupper(trim((string) $pasta->getNomeAcao()));
    }
}
