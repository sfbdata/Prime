<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Cliente\Entity\Cliente;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPagamento;

/**
 * De onde o {@see MontadorDeContextoDaPasta} lê os dados da pasta além das movimentações (que vêm
 * da {@see FonteDeMovimentacoesDoPush}). Interface para o montador ser testado por unidade com
 * dados canned; a implementação Doctrine filtra por pasta E tenant em toda consulta — roda no
 * worker, onde o TenantFilter é inerte.
 *
 * Vive aqui, e não nos repositórios da Pasta/Tarefa, porque esta frente não edita arquivo de outro
 * domínio. Mover para lá é decisão do orquestrador.
 */
interface FonteDeDadosDaPasta
{
    /**
     * Clientes vinculados à pasta que são deste escritório.
     *
     * @return list<Cliente>
     */
    public function clientesDaPasta(Tenant $tenant, Pasta $pasta): array;

    /**
     * Metas da pasta: abertas primeiro (prazo mais próximo antes), depois as concluídas (mais
     * recentes antes).
     *
     * @return list<Tarefa>
     */
    public function tarefasDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array;

    /**
     * Anotações internas (aba Dados), mais recente primeiro.
     *
     * @return list<PastaMensagem>
     */
    public function anotacoesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array;

    /**
     * Observações da aba Detalhes, mais recente primeiro.
     *
     * @return list<PastaObservacaoDetalhes>
     */
    public function observacoesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array;

    /**
     * Documentos juntados (só metadados são usados), mais recente primeiro.
     *
     * @return list<PastaDocumento>
     */
    public function documentosDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array;

    /**
     * Itens do checklist de documentação, na ordem da tela.
     *
     * @return list<PastaChecklistItem>
     */
    public function checklistDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array;

    /**
     * Pagamentos da pasta, vencimento mais recente primeiro.
     *
     * @return list<PastaPagamento>
     */
    public function pagamentosDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array;

    /**
     * Observações da aba Financeiro, mais recente primeiro.
     *
     * @return list<PastaObservacaoFinanceira>
     */
    public function observacoesFinanceirasDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array;
}
