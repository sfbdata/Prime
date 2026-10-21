<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\DTO\PlanoDoCatalogo;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Service\CatalogoDePlanos;

/**
 * "Planos e limites" e as "Faixas de alerta de consumo" de "Armazenamento" no Painel do Master
 * (desenho 05 - Perfis 1.2, linhas 194-210; spec docs/specs/perfis-1.2-planos-e-limites.md P1 e P6).
 * Só leitura.
 *
 * Os planos vêm de `CatalogoDePlanos::todos()` (contrato com a Contribua), com quantas contas usam cada
 * um. As faixas e a chave do bloqueio vêm da leitura `ConfiguracaoDoEspacoRepository::atual()`, que
 * nunca grava (o contrato da f6 não tem leitura própria das faixas; ver o relatório da frente).
 */
final class ConsultarPlanosUseCase
{
    public function __construct(
        private readonly CatalogoDePlanos $catalogo,
        private readonly ConfiguracaoDoEspacoRepository $configuracoes,
    ) {
    }

    /**
     * @return array{planos: list<array{codigo: string, nome: string, cotaGb: int, precoCentavos: int, limiteUsuarios: ?int, descricao: string, ativo: bool, escritorios: int}>, faixas: array{atencao: int, alerta: int, critico: int, bloqueio: int}, bloqueioAtivo: bool}
     */
    public function executar(): array
    {
        $configuracao = $this->configuracoes->atual();

        return [
            'planos' => array_map(static fn (PlanoDoCatalogo $p): array => [
                'codigo'         => $p->codigo,
                'nome'           => $p->nome,
                'cotaGb'         => $p->cotaGb,
                'precoCentavos'  => $p->precoCentavos,
                'limiteUsuarios' => $p->limiteUsuarios,
                'descricao'      => $p->descricao,
                'ativo'          => $p->ativo,
                'escritorios'    => $p->escritorios,
            ], $this->catalogo->todos()),
            'faixas' => [
                'atencao'  => $configuracao->getFaixaAtencao(),
                'alerta'   => $configuracao->getFaixaAlerta(),
                'critico'  => $configuracao->getFaixaCritico(),
                'bloqueio' => $configuracao->getFaixaBloqueio(),
            ],
            'bloqueioAtivo' => $configuracao->isBloqueioAtivo(),
        ];
    }
}
