<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Cliente\Entity\Cliente;
use App\Pasta\Entity\Pasta;

/**
 * Uma linha de `buscar_pastas`: as colunas que a Lista do Expediente já mostra a quem tem o módulo, com
 * o MESMO texto da tela (`pasta/_tabela.html.twig`) — nada além (sem documento nem financeiro).
 *
 * - `identificador`: o de `identificador_pasta()` (derivado da cobrança judicializada, senão o
 *   `nome_cliente` gravado), resolvido por quem chama — o agente não pode dizer um nome e a tela outro;
 * - `clientes`: os nomes de exibição dos clientes vinculados — SÓ quando não há identificador, como a Lista;
 * - `acao`: o nome da ação, senão a classe, o assunto ou o número do processo principal, como a coluna.
 */
final class PastaDoAgenteOutput
{
    /**
     * @param list<string>                                    $clientes
     * @param array{id: int, nome: string}|null              $responsavel
     * @param list<array{id: int, nome: string, cor: ?string}> $marcadores
     */
    private function __construct(
        public readonly int $id,
        public readonly ?string $nup,
        public readonly ?string $identificador,
        public readonly array $clientes,
        public readonly ?string $acao,
        public readonly ?array $responsavel,
        public readonly array $marcadores,
        public readonly string $situacao,
        public readonly string $prioridade,
        public readonly string $abertaEm,
        public readonly bool $excluida,
        public readonly bool $oculta = false,
    ) {
    }

    /**
     * Pasta oculta pelo cadeado para quem conversa com o agente (spec pasta-cadeado D2, desenho L4383): só
     * o número e o cliente, e `oculta` para o agente dizer que ela está trancada em vez de inventar.
     */
    public static function oculta(Pasta $pasta, ?string $identificador): self
    {
        $identificador = $identificador !== null && $identificador !== '' ? $identificador : null;

        return new self(
            id: (int) $pasta->getId(),
            nup: $pasta->getNup(),
            identificador: $identificador,
            clientes: $identificador === null
                ? array_values(array_map(static fn (Cliente $c): string => $c->getNomeExibicao(), $pasta->getClientes()->toArray()))
                : [],
            acao: null,
            responsavel: null,
            marcadores: [],
            situacao: '',
            prioridade: '',
            abertaEm: '',
            excluida: false,
            oculta: true,
        );
    }

    public static function fromEntity(Pasta $pasta, ?string $identificador): self
    {
        $responsavel = $pasta->getResponsavel();
        $marcadores = [];
        foreach ($pasta->getMarcadores() as $marcador) {
            $marcadores[] = ['id' => (int) $marcador->getId(), 'nome' => $marcador->getNome(), 'cor' => $marcador->getCor()];
        }

        $identificador = $identificador !== null && $identificador !== '' ? $identificador : null;

        return new self(
            id: (int) $pasta->getId(),
            nup: $pasta->getNup(),
            identificador: $identificador,
            // Como a coluna da Lista: os clientes só aparecem quando a pasta não tem identificador.
            clientes: $identificador === null
                ? array_values(array_map(static fn (Cliente $c): string => $c->getNomeExibicao(), $pasta->getClientes()->toArray()))
                : [],
            acao: self::acao($pasta),
            responsavel: $responsavel !== null ? ['id' => (int) $responsavel->getId(), 'nome' => (string) $responsavel->getFullName()] : null,
            marcadores: $marcadores,
            situacao: $pasta->getSituacao(),
            prioridade: $pasta->getPrioridade()->value,
            abertaEm: $pasta->getDataAbertura()->format('Y-m-d'),
            excluida: $pasta->estaExcluida(),
        );
    }

    private static function acao(Pasta $pasta): ?string
    {
        if ($pasta->getNomeAcao() !== null && $pasta->getNomeAcao() !== '') {
            return $pasta->getNomeAcao();
        }

        $processo = $pasta->getProcessoPrincipal();
        if ($processo === null) {
            return null;
        }

        return $processo->getClasseProcessual() ?: $processo->getAssuntoProcessual() ?: $processo->getNumeroProcesso();
    }
}
