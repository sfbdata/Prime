<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\LinkExternoDoPushException;

/**
 * O que o link externo do Push pode mostrar, lido AO VIVO, e se a pasta pode ser compartilhada.
 *
 * Movimentações: a MESMA consulta da aba Push (`PastaController::show`): publicações DJEN casadas
 * pelo número CNJ dos processos da pasta, mais recente primeiro, teto 100. A consulta já projeta só
 * o começo do teor (o título do cartão), nunca o teor inteiro.
 *
 * Análises: resumos do Push concluídos, não excluídos e SEM o cadeado "interna do escritório".
 *
 * O tenant é sempre o passado pelo chamador (o do link, na página pública): aqui não há sessão.
 */
final class ConteudoDoPushCompartilhado
{
    /** O mesmo teto da aba Push (`PastaController::PUSH_LIMITE`). */
    public const LIMITE_MOVIMENTACOES = 100;
    private const LIMITE_ANALISES = 20;

    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    /** @return PublicacaoDjenListaItem[] */
    public function movimentacoes(Pasta $pasta, Tenant $tenant): array
    {
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }

        return $this->publicacoes->listarItensPorNumerosDoTenant($tenant, $numeros, self::LIMITE_MOVIMENTACOES);
    }

    /** @return AnaliseDeInteligencia[] mais recente primeiro */
    public function analisesCompartilhaveis(Pasta $pasta, Tenant $tenant): array
    {
        $todas = $this->analises->listarPorAlvo(
            $tenant,
            AnaliseDeInteligencia::ALVO_PASTA,
            (int) $pasta->getId(),
            self::LIMITE_ANALISES,
            TipoDeAnalise::ResumoPush,
        );

        return array_values(array_filter(
            $todas,
            static fn (AnaliseDeInteligencia $a): bool => $a->estaConcluida() && !$a->isInternaDoEscritorio(),
        ));
    }

    /**
     * O nome do cliente como o cabeçalho da pasta mostra: o principal do cadastro ou, sem ele, o
     * identificador digitado na pasta (`nomeCliente`). `null` quando não há nenhum.
     */
    public function nomeDoCliente(Pasta $pasta): ?string
    {
        $nome = $pasta->getClientePrincipal()?->getNomeExibicao() ?? $pasta->getNomeCliente();
        $nome = trim((string) $nome);

        return $nome === '' ? null : $nome;
    }

    /**
     * O motivo que impede gerar o link, com o texto do desenho, ou `null` se pode. A ordem é a de
     * `pushLinkRegra` ([EXP] 4382-4386). A lápide (pasta excluída) bloqueia como cancelada.
     */
    public function motivoDoBloqueio(Pasta $pasta, Tenant $tenant): ?string
    {
        if ($pasta->getProcessoPrincipal() === null) {
            return LinkExternoDoPushException::SEM_PROCESSO;
        }
        if ($pasta->estaExcluida() || $pasta->getSituacao() === Pasta::SITUACAO_CANCELADA) {
            return LinkExternoDoPushException::PASTA_CANCELADA;
        }
        if ($pasta->getSituacao() === Pasta::SITUACAO_ARQUIVADA) {
            return LinkExternoDoPushException::PASTA_ARQUIVADA;
        }
        if ($pasta->estaTrancada()) {
            return LinkExternoDoPushException::PASTA_TRANCADA;
        }
        if ($this->temProcessoEmSegredo($pasta)) {
            return LinkExternoDoPushException::SEGREDO_DE_JUSTICA;
        }
        if ($this->movimentacoes($pasta, $tenant) === []) {
            return LinkExternoDoPushException::SEM_MOVIMENTACOES;
        }

        return null;
    }

    /**
     * A regra única de "o link ainda pode mostrar alguma coisa", usada pela página e pelo pedido de
     * renovação. Qualquer mudança da pasta depois de criar o link que impediria GERAR um link novo
     * também desliga o existente (decisão do orquestrador, 19/10): excluída, trancada, cancelada,
     * arquivada, processo em segredo, ou o processo do link desvinculado da pasta.
     */
    public function linkVisivel(LinkExternoDoPush $link): bool
    {
        $pasta = $link->getPasta();
        if (
            $pasta->estaExcluida()
            || $pasta->estaTrancada()
            || \in_array($pasta->getSituacao(), [Pasta::SITUACAO_CANCELADA, Pasta::SITUACAO_ARQUIVADA], true)
            || $this->temProcessoEmSegredo($pasta)
        ) {
            return false;
        }

        foreach ($pasta->getProcessos() as $processo) {
            if ($processo === $link->getProcesso()) {
                return true;
            }
        }

        return false;
    }

    /** Segredo de justiça em QUALQUER processo da pasta: a página pública mostra o push de todos. */
    public function temProcessoEmSegredo(Pasta $pasta): bool
    {
        foreach ($pasta->getProcessos() as $processo) {
            if (($processo->getNivelSigilo() ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
