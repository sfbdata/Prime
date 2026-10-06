<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ContextoDeAnalise;
use App\Inteligencia\DTO\MovimentacaoDeContexto;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;

/**
 * Monta o contexto do "Resumir com IA" de UMA pasta: cabeçalho do caso + publicações (DJEN) +
 * movimentações (Datajud) dos processos vinculados, mais recente primeiro, texto plano, PII
 * mascarada (se o escritório não desligou) e truncado a um orçamento.
 *
 * REGRA (D4): processo com `nivelSigilo > 0` → {@see ContextoBloqueadoException}, antes de qualquer
 * leitura. Nunca sai.
 *
 * Tenant explícito em tudo: roda no worker. Um processo da pasta que não seja deste tenant (não
 * deveria existir) é ignorado em silêncio, nunca lido.
 */
final class MontadorDeContextoDoPush
{
    public const LIMITE_PUBLICACOES = 30;
    public const LIMITE_MOVIMENTACOES = 30;

    /** Caracteres por item e orçamento total do contexto (o modelo não precisa do processo inteiro). */
    public const TAMANHO_MAXIMO_DO_ITEM = 3000;
    public const ORCAMENTO_TOTAL = 60000;

    public function __construct(
        private readonly FonteDeMovimentacoesDoPush $fonte,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly MascaradorDeDadosPessoais $mascarador,
    ) {
    }

    /**
     * @throws ContextoBloqueadoException
     */
    public function para(Tenant $tenant, Pasta $pasta): ContextoDeAnalise
    {
        $processos = $this->processosDaPasta($tenant, $pasta);
        $cabecalho = $this->cabecalho($tenant, $pasta, $processos);

        if ($processos === []) {
            return new ContextoDeAnalise($cabecalho, [], ContextoDeAnalise::hashDe([]), []);
        }

        $numeros = array_map(static fn (Processo $p): string => $p->getNumeroProcesso(), $processos);
        $ids = array_map(static fn (Processo $p): int => (int) $p->getId(), $processos);
        $mascarar = $this->configuracoes->findDoTenant($tenant)?->isMascararDadosPessoais() ?? true;

        $candidatos = [];
        foreach ($this->fonte->publicacoesDoTenant($tenant, $numeros, self::LIMITE_PUBLICACOES) as $publicacao) {
            $texto = $this->preparar(self::textoPlano($publicacao->getTexto()), $mascarar);
            if ($texto === '') {
                continue;
            }
            $fonte = 'DJEN · ' . $publicacao->getSiglaTribunal();
            if ($publicacao->getNomeOrgao() !== null && $publicacao->getNomeOrgao() !== '') {
                $fonte .= ' · ' . $publicacao->getNomeOrgao();
            }
            $candidatos[] = [
                'ordem' => $publicacao->getDataDisponibilizacao()?->getTimestamp() ?? -1,
                'item' => new MovimentacaoDeContexto(
                    'pub:' . (int) $publicacao->getId(),
                    $publicacao->getDataDisponibilizacao()?->format('d/m/Y'),
                    $publicacao->getTipoComunicacao() ?: 'Publicação',
                    $fonte,
                    $texto,
                ),
            ];
        }

        foreach ($this->fonte->movimentacoesDoTenant($tenant, $ids, self::LIMITE_MOVIMENTACOES) as $movimentacao) {
            $descricao = $movimentacao->getDescricao();
            $complementos = $movimentacao->getComplementosResumo();
            if ($complementos !== null) {
                $descricao .= ' (' . $complementos . ')';
            }
            $texto = $this->preparar($descricao, $mascarar);
            if ($texto === '') {
                continue;
            }
            $fonte = 'Datajud';
            if ($movimentacao->getOrgao() !== null && $movimentacao->getOrgao() !== '') {
                $fonte .= ' · ' . $movimentacao->getOrgao();
            }
            $data = $movimentacao->getDataMovimentacao();
            $candidatos[] = [
                'ordem' => $data?->getTimestamp() ?? -1,
                'item' => new MovimentacaoDeContexto(
                    'mov:' . (int) $movimentacao->getId(),
                    $data?->format('d/m/Y'),
                    $movimentacao->getTipo() ?: 'Movimentação',
                    $fonte,
                    $texto,
                ),
            ];
        }

        // Mais recente primeiro; empate pela chave, para a ordem (e o hash) serem estáveis.
        usort($candidatos, static function (array $a, array $b): int {
            return [$b['ordem'], $b['item']->chave] <=> [$a['ordem'], $a['item']->chave];
        });

        $itens = [];
        $gasto = 0;
        foreach ($candidatos as $candidato) {
            /** @var MovimentacaoDeContexto $item */
            $item = $candidato['item'];
            $tamanho = mb_strlen($item->texto);
            if ($itens !== [] && $gasto + $tamanho > self::ORCAMENTO_TOTAL) {
                break;
            }
            $itens[] = $item;
            $gasto += $tamanho;
        }

        return new ContextoDeAnalise($cabecalho, $itens, ContextoDeAnalise::hashDe($itens), $numeros);
    }

    /**
     * Chaves do que entraria no contexto hoje, sem carregar texto — para a tela contar o que ainda
     * não foi analisado. Pasta com processo sigiloso devolve lista vazia (não há o que contar: a
     * análise é recusada de qualquer forma).
     *
     * @return list<string>
     */
    public function chavesAtuais(Tenant $tenant, Pasta $pasta): array
    {
        try {
            $processos = $this->processosDaPasta($tenant, $pasta);
        } catch (ContextoBloqueadoException) {
            return [];
        }

        if ($processos === []) {
            return [];
        }

        return $this->fonte->chavesDoTenant(
            $tenant,
            array_map(static fn (Processo $p): string => $p->getNumeroProcesso(), $processos),
            array_map(static fn (Processo $p): int => (int) $p->getId(), $processos),
            self::LIMITE_PUBLICACOES,
            self::LIMITE_MOVIMENTACOES,
        );
    }

    /**
     * @return list<Processo>
     * @throws ContextoBloqueadoException
     */
    private function processosDaPasta(Tenant $tenant, Pasta $pasta): array
    {
        $processos = [];
        foreach ($pasta->getProcessos() as $processo) {
            $tenantDoProcesso = $processo->getTenant();
            $mesmoTenant = $tenantDoProcesso === $tenant
                || ($tenant->getId() !== null && $tenantDoProcesso?->getId() === $tenant->getId());
            if (!$mesmoTenant) {
                continue;
            }

            if (($processo->getNivelSigilo() ?? 0) > 0) {
                throw new ContextoBloqueadoException($processo->getNumeroProcesso());
            }

            $processos[] = $processo;
        }

        return $processos;
    }

    /**
     * @param list<Processo> $processos
     * @return array<string, string>
     */
    private function cabecalho(Tenant $tenant, Pasta $pasta, array $processos): array
    {
        $principal = $pasta->getProcessoPrincipal() ?? ($processos[0] ?? null);
        if ($principal !== null && !in_array($principal, $processos, true)) {
            $principal = $processos[0] ?? null;
        }

        $equipe = $processos === [] ? [] : $this->fonte->nomesDaEquipe($tenant);

        return [
            'pasta' => (string) $pasta->getNup(),
            'processo' => $processos === []
                ? 'sem processo vinculado'
                : implode(', ', array_map(static fn (Processo $p): string => $p->getNumeroProcesso(), $processos)),
            'classe' => $principal?->getClasseProcessual() ?: 'não informada',
            'assunto' => $principal?->getAssuntoProcessual() ?: 'não informado',
            'tribunal' => $principal?->getSiglaTribunal() ?: 'não informado',
            'orgao' => $principal?->getOrgaoJulgador() ?: 'não informado',
            'responsavel' => $pasta->getResponsavel()?->getFullName() ?: 'não definido',
            'equipe' => $equipe === [] ? 'não informada' : implode(', ', $equipe),
        ];
    }

    /** HTML do DJEN → texto plano (quebras preservadas como espaço, entidades decodificadas). */
    private static function textoPlano(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $texto = (string) preg_replace('/<\s*(br|p|div|li|tr|h[1-6])\b[^>]*>/i', ' ', $html);
        $texto = strip_tags($texto);

        return html_entity_decode($texto, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function preparar(string $texto, bool $mascarar): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if ($texto === '') {
            return '';
        }

        // A tag é a fronteira do que é dado no prompt: o texto não pode fechá-la por conta própria.
        $texto = str_ireplace(['</movimentacoes', '<movimentacoes'], ['[/movimentacoes', '[movimentacoes'], $texto);

        if (mb_strlen($texto) > self::TAMANHO_MAXIMO_DO_ITEM) {
            $texto = mb_substr($texto, 0, self::TAMANHO_MAXIMO_DO_ITEM) . '…';
        }

        return $mascarar ? $this->mascarador->mascarar($texto) : $texto;
    }
}
