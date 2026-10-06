<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;

/**
 * A folha "Resumo da pasta" (menu ⋮ → Imprimir resumo), inteira e já decidida.
 *
 * Desenho: `imprimirResumo()` em "02 - EXPEDIENTES 1.2.3.dc.html" — Dados da pasta, Pendências,
 * Metas abertas (até 12, com atraso), Últimas movimentações (5 do Push) e Financeiro. Aqui só
 * entra o que o sistema TEM: o protótipo lia de `localStorage` campos que não existem (foto,
 * carteira, etiqueta de registro), e nenhum deles aparece.
 *
 * O Financeiro é opcional por construção (`null` = a seção não existe na folha): quem decide se
 * o usuário o vê é o controller, não o template.
 */
final readonly class PastaResumoImpressaoOutput
{
    public const LIMITE_METAS          = 12;
    public const LIMITE_MOVIMENTACOES  = 5;

    /**
     * @param list<array{nome: string, documento: ?string, principal: bool}>                                         $clientes
     * @param list<array{numero: string, classe: ?string, tribunal: ?string, orgao: ?string, principal: bool}>       $processos
     * @param list<string>                                                                                           $pendencias
     * @param list<array{titulo: string, responsaveis: string, prazo: ?string, atrasoDias: int}>                     $metasAbertas
     * @param list<array{data: ?string, tipo: ?string, orgao: ?string, processo: ?string}>                           $movimentacoes
     * @param array{valorCausa: string, contrato: string, recebido: string, previsto: string, lancamentos: int}|null $financeiro
     */
    private function __construct(
        public string $numero,
        public string $titulo,
        public ?string $documento,
        public ?string $responsavel,
        public string $situacao,
        public string $prioridade,
        public string $abertura,
        public ?string $acao,
        public array $clientes,
        public array $processos,
        public array $pendencias,
        public int $totalMetasAbertas,
        public array $metasAbertas,
        public array $movimentacoes,
        public ?array $financeiro,
    ) {
    }

    /**
     * @param PublicacaoDjenListaItem[] $publicacoes as do Push, já da mais recente para a mais antiga
     * @param PastaPagamento[]          $pagamentos  os do cartão Pagamentos da aba Financeiro
     */
    public static function montar(
        Pasta $pasta,
        PastaPendenciasOutput $pendencias,
        array $publicacoes,
        array $pagamentos,
        bool $incluirFinanceiro,
        ?\DateTimeImmutable $hoje = null,
    ): self {
        $hoje      = ($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $principal = $pasta->getClientePrincipal();
        $processo  = $pasta->getProcessoPrincipal();

        // O título segue o cabeçalho da tela: cliente do cadastro, senão o identificador da pasta.
        $titulo = $principal?->getNomeExibicao() ?? ($pasta->getNomeCliente() ?: 'Cliente não informado');

        // A Ação também: o campo da pasta, senão o que o processo vinculado diz.
        $acao = $pasta->getNomeAcao()
            ?: ($processo !== null ? ($processo->getClasseProcessual() ?: $processo->getAssuntoProcessual()) : null);

        $clientes = [];
        foreach ($pasta->getClientes() as $cliente) {
            $clientes[] = [
                'nome'      => $cliente->getNomeExibicao(),
                'documento' => self::documento($cliente),
                'principal' => $principal !== null && $cliente === $principal,
            ];
        }

        $processos = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $p           = $vinculo->getProcesso();
            $processos[] = [
                'numero'    => $p->getNumeroProcesso(),
                'classe'    => $p->getClasseProcessual() ?: null,
                'tribunal'  => $p->getSiglaTribunal() ?: null,
                'orgao'     => $p->getOrgaoJulgador() ?: null,
                'principal' => $processo !== null && $p === $processo,
            ];
        }

        $textos = [];
        foreach ($pendencias->porAba as $pendencia) {
            $textos[] = self::maiuscula($pendencia['txt']);
        }

        [$totalAbertas, $metas] = self::metasAbertas($pasta, $hoje);

        $movimentacoes = [];
        foreach (array_slice(array_values($publicacoes), 0, self::LIMITE_MOVIMENTACOES) as $pub) {
            $movimentacoes[] = [
                'data'     => $pub->dataDisponibilizacao,
                'tipo'     => $pub->tipoComunicacao,
                'orgao'    => $pub->nomeOrgao,
                'processo' => $pub->numeroProcessoExibicao,
            ];
        }

        return new self(
            numero: (string) $pasta->getNup(),
            titulo: $titulo,
            documento: $principal !== null ? self::documento($principal) : null,
            responsavel: $pasta->getResponsavel()?->getFullName() ?: null,
            situacao: $pasta->getSituacao() === 'arquivado' ? 'Arquivado' : 'Ativo',
            prioridade: $pasta->getPrioridadeLabel(),
            abertura: $pasta->getDataAbertura()->format('d/m/Y'),
            acao: $acao ?: null,
            clientes: $clientes,
            processos: $processos,
            pendencias: $textos,
            totalMetasAbertas: $totalAbertas,
            metasAbertas: $metas,
            movimentacoes: $movimentacoes,
            financeiro: $incluirFinanceiro ? self::financeiro($pasta, $pagamentos) : null,
        );
    }

    /**
     * Metas não concluídas, do prazo mais próximo (ou mais atrasado) ao mais distante; as sem
     * prazo vão para o fim — não há data para ordená-las. Devolve também o TOTAL de abertas: a
     * folha lista no máximo 12, mas o título diz quantas existem.
     *
     * @return array{0: int, 1: list<array{titulo: string, responsaveis: string, prazo: ?string, atrasoDias: int}>}
     */
    private static function metasAbertas(Pasta $pasta, \DateTimeImmutable $hoje): array
    {
        $abertas = [];
        foreach ($pasta->getTarefas() as $tarefa) {
            if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
                $abertas[] = $tarefa;
            }
        }

        usort($abertas, static function (Tarefa $a, Tarefa $b): int {
            $pa = $a->getPrazo();
            $pb = $b->getPrazo();
            if ($pa === null || $pb === null) {
                return ($pa === null) <=> ($pb === null);
            }

            return $pa <=> $pb;
        });

        $linhas = [];
        foreach (array_slice($abertas, 0, self::LIMITE_METAS) as $tarefa) {
            $prazo  = $tarefa->getPrazo();
            $dias   = $prazo !== null ? (int) $hoje->diff($prazo->setTime(0, 0))->format('%r%a') : 0;
            $nomes  = [];
            foreach ($tarefa->getResponsaveis() as $responsavel) {
                $nome = (string) $responsavel->getFullName();
                if ($nome !== '') {
                    $nomes[] = $nome;
                }
            }

            $linhas[] = [
                'titulo'       => $tarefa->getTitulo(),
                'responsaveis' => $nomes !== [] ? implode(', ', $nomes) : 'Sem responsável',
                'prazo'        => $prazo?->format('d/m/Y'),
                'atrasoDias'   => $dias < 0 ? -$dias : 0,
            ];
        }

        return [count($abertas), $linhas];
    }

    /**
     * @param PastaPagamento[] $pagamentos
     *
     * @return array{valorCausa: string, contrato: string, recebido: string, previsto: string, lancamentos: int}
     */
    private static function financeiro(Pasta $pasta, array $pagamentos): array
    {
        $resumo = PastaPagamentosOutput::montar($pagamentos);

        $contrato = match (true) {
            $pasta->isProBono()                          => 'Pró-bono',
            $pasta->getSituacaoContrato() === 'PENDENTE' => 'Contrato pendente de assinatura',
            default                                      => 'Contrato assinado',
        };

        return [
            'valorCausa'  => PastaFinanceiroOutput::formatarReais($pasta->getValorCausa()),
            'contrato'    => $contrato,
            'recebido'    => $resumo->recebidoFormatado,
            'previsto'    => $resumo->previstoFormatado,
            'lancamentos' => $resumo->total,
        ];
    }

    private static function documento(Cliente $cliente): ?string
    {
        $documento = match (true) {
            $cliente instanceof ClientePF => $cliente->getCpf(),
            $cliente instanceof ClientePJ => $cliente->getCnpj(),
            default                       => '',
        };

        return $documento !== '' ? $documento : null;
    }

    private static function maiuscula(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
