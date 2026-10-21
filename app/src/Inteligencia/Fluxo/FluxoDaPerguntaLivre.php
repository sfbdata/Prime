<?php

declare(strict_types=1);

namespace App\Inteligencia\Fluxo;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Prompt\PromptDaPergunta;
use App\Inteligencia\Repository\PerguntaDaConversaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDoAgente;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Pasta\Entity\Pasta;

/**
 * A aba "Perguntar" do drawer (spec inteligencia-drawer-da-pasta, F3) como {@see FluxoDeAnalise}:
 * o contexto é o do agente gravado na linha (o Gestor), com o financeiro e as abas ocultas que a
 * solicitação decidiu (`contexto_resumo`; o worker obedece); a pergunta vem de
 * `inteligencia_pergunta`, e o histórico são as últimas {@see HISTORICO} perguntas concluídas da
 * MESMA pessoa na MESMA pasta, com as respostas.
 *
 * Tudo que volta ao provedor (pergunta, perguntas anteriores e respostas anteriores do modelo) passa
 * pela neutralização e pela máscara do escritório (`MontadorDeContextoDaPasta::analiseAnterior`, a
 * mesma regra do IA-033). Do histórico sai o que a pergunta de agora não poderia ler: resposta que
 * leu aba hoje oculta para quem pergunta, ou que leu o financeiro quando esta pergunta não o lê.
 *
 * Contexto sem linha nas seções NÃO é falha: a pergunta é respondida com o que houver ("Não
 * encontrei informação suficiente"). Linha sem pergunta é dado corrompido: `\LogicException` (o
 * handler marca `falhou` sem chamar o provedor). Sigilo: o montador lança antes de ler.
 */
final class FluxoDaPerguntaLivre implements FluxoDeAnalise
{
    /** Perguntas concluídas que voltam ao modelo (8 mensagens, como o desenho: `hist.slice(-8)`). */
    public const HISTORICO = 4;

    /** Teto de cada resposta anterior no histórico; o corte é declarado com "…". */
    public const TAMANHO_MAXIMO_DA_RESPOSTA_ANTERIOR = 1500;

    public function __construct(
        private readonly MontadorDeContextoDaPasta $montador,
        private readonly PromptDaPergunta $prompt,
        private readonly InterpretadorDeRespostaDoAgente $interpretador,
        private readonly PerguntaDaConversaRepository $perguntas,
    ) {
    }

    public function tipo(): TipoDeAnalise
    {
        return TipoDeAnalise::PerguntaLivre;
    }

    public function preparar(AnaliseDeInteligencia $analise, Tenant $tenant, Pasta $pasta): PedidoDeLinguagem
    {
        $pergunta = $this->perguntas->daAnalise($analise, $tenant) ?? throw new \LogicException('Pergunta livre sem pergunta.');
        $agente = $analise->getAgente() ?? Agente::Gestor;
        $contexto = $this->montador->para($tenant, $pasta, $agente, self::leFinanceiro($analise), self::abasOcultasDo($analise));
        $analise->registrarContexto($contexto->hash, $contexto->resumo());

        return $this->prompt->montar($contexto, $this->protegido($tenant, $pergunta->getTexto()), $this->historico($pergunta, $analise, $tenant));
    }

    /**
     * As trocas anteriores que podem voltar ao modelo, já protegidas, em ordem cronológica.
     *
     * @return list<array{pergunta: string, resposta: string}>
     */
    private function historico(PerguntaDaConversa $atual, AnaliseDeInteligencia $analise, Tenant $tenant): array
    {
        $trocas = [];
        foreach ($this->perguntas->concluidasAntesDe($atual, $tenant, self::HISTORICO) as $anterior) {
            $resposta = $anterior->getAnalise()->getTextoDaAnalise() ?? $anterior->getAnalise()->getResumo() ?? '';
            if (!self::podeVoltar($anterior->getAnalise(), $analise) || trim($resposta) === '') {
                continue;
            }
            $trocas[] = [
                'pergunta' => $this->protegido($tenant, $anterior->getTexto()),
                'resposta' => $this->protegido($tenant, self::cortar($resposta)),
            ];
        }

        return $trocas;
    }

    /** A anterior não leu nada que a pergunta de agora não pode ler (abas ocultas, financeiro). */
    private static function podeVoltar(AnaliseDeInteligencia $anterior, AnaliseDeInteligencia $atual): bool
    {
        if (self::leFinanceiro($anterior) && !self::leFinanceiro($atual)) {
            return false;
        }

        return VisibilidadeDaAnaliseDaPasta::legivelCom($anterior, self::abasOcultasDo($atual));
    }

    /** Neutralizado e mascarado pela preferência atual do escritório (vazio vira texto vazio). */
    private function protegido(Tenant $tenant, string $texto): string
    {
        return $this->montador->analiseAnterior($tenant, $texto) ?? '';
    }

    private static function cortar(string $resposta): string
    {
        return mb_strlen($resposta) > self::TAMANHO_MAXIMO_DA_RESPOSTA_ANTERIOR
            ? mb_substr($resposta, 0, self::TAMANHO_MAXIMO_DA_RESPOSTA_ANTERIOR) . '…'
            : $resposta;
    }

    private static function leFinanceiro(AnaliseDeInteligencia $analise): bool
    {
        return ($analise->getContextoResumo()['financeiro'] ?? false) === true;
    }

    /**
     * As abas ocultas para quem perguntou, como a solicitação gravou.
     *
     * @return list<string>
     */
    private static function abasOcultasDo(AnaliseDeInteligencia $analise): array
    {
        $abas = $analise->getContextoResumo()['abasOcultas'] ?? [];

        return \is_array($abas) ? array_values(array_filter($abas, 'is_string')) : [];
    }

    public function motivoDeContextoVazio(): string
    {
        return 'sem dados da pasta';
    }

    public function interpretar(string $texto): ResultadoDaAnalise
    {
        return $this->interpretador->interpretar($texto);
    }

    public function ancoraDaPasta(): string
    {
        return '#ia';
    }

    public function textoDaNotificacao(AnaliseDeInteligencia $analise, Pasta $pasta): string
    {
        return sprintf('A resposta à sua pergunta na pasta %s ficou pronta.', (string) $pasta->getNup());
    }
}
