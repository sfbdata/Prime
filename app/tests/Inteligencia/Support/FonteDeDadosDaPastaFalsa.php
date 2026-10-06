<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\FonteDeDadosDaPasta;
use App\Pasta\Entity\Pasta;

/**
 * Fonte canned para os testes de unidade do montador/UseCase dos agentes: devolve o que o teste
 * colocar nas listas públicas, respeitando só o limite (o recorte por pasta e tenant é provado no
 * teste funcional da implementação Doctrine).
 */
final class FonteDeDadosDaPastaFalsa implements FonteDeDadosDaPasta
{
    /** @var list<\App\Cliente\Entity\Cliente> */
    public array $clientes = [];

    /** @var list<\App\Entity\Tarefa\Tarefa> */
    public array $tarefas = [];

    /** @var list<\App\Pasta\Entity\PastaMensagem> */
    public array $anotacoes = [];

    /** @var list<\App\Pasta\Entity\PastaObservacaoDetalhes> */
    public array $observacoes = [];

    /** @var list<\App\Pasta\Entity\PastaDocumento> */
    public array $documentos = [];

    /** @var list<\App\Pasta\Entity\PastaChecklistItem> */
    public array $checklist = [];

    /** @var list<\App\Pasta\Entity\PastaPagamento> */
    public array $pagamentos = [];

    /** @var list<\App\Pasta\Entity\PastaObservacaoFinanceira> */
    public array $observacoesFinanceiras = [];

    public function clientesDaPasta(Tenant $tenant, Pasta $pasta): array
    {
        return $this->clientes;
    }

    public function tarefasDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return array_slice($this->tarefas, 0, $limite);
    }

    public function anotacoesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return array_slice($this->anotacoes, 0, $limite);
    }

    public function observacoesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return array_slice($this->observacoes, 0, $limite);
    }

    public function documentosDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return array_slice($this->documentos, 0, $limite);
    }

    public function checklistDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return array_slice($this->checklist, 0, $limite);
    }

    public function pagamentosDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return array_slice($this->pagamentos, 0, $limite);
    }

    public function observacoesFinanceirasDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        return array_slice($this->observacoesFinanceiras, 0, $limite);
    }
}
