<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CadeadoNaTelaOutput;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use App\Pasta\Repository\PedidoDeAcessoAPastaRepository;
use Psr\Clock\ClockInterface;

/**
 * Monta o `CadeadoNaTelaOutput` de uma pasta para uma pessoa (a tela da pasta e a tela trancada).
 *
 * A grade do modal segue o `cdAbrir` do desenho (L6116): sem cadeado, todos começam em `Editar`; com
 * cadeado `Todos`, todos em `Oculto`; em `PorPessoa`, o nível gravado de cada um, ou `Oculto`.
 */
final class TelaDoCadeado
{
    public function __construct(
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EquipeDoCadeado $equipe,
        private readonly PedidoDeAcessoAPastaRepository $pedidos,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function montar(Pasta $pasta, User $pessoa, Tenant $tenant): CadeadoNaTelaOutput
    {
        $cadeado        = $this->acessoAPasta->cadeadoDe($pasta);
        $podeConfigurar = $this->acessoAPasta->podeConfigurar($pessoa, $tenant);
        $ultimo         = $cadeado !== null && !$podeConfigurar ? $this->pedidos->ultimoDe($pasta, $pessoa) : null;
        $agora          = $this->relogio->now();
        $aguardando     = $ultimo !== null && $ultimo->aguardandoEm($agora);
        $recusadoAte    = $ultimo !== null && $ultimo->foiRecusado() ? $ultimo->seguraNovoPedidoAte($agora) : null;

        return new CadeadoNaTelaOutput(
            nivel: $this->acessoAPasta->nivel($pessoa, $pasta)->value,
            podeConfigurar: $podeConfigurar,
            temCadeado: $cadeado !== null,
            modo: $cadeado?->getModo()->value,
            selo: $cadeado?->getModo()->selo() ?? 'Livre',
            aguardando: $aguardando,
            horaDoPedido: $ultimo?->getPedidoEm()->format('H:i') ?? '',
            recusado: !$aguardando && $ultimo !== null && $ultimo->foiRecusado(),
            pedirDeNovoEm: $recusadoAte !== null ? $recusadoAte->format('d/m') . ' às ' . $recusadoAte->format('H:i') : '',
            equipe: $podeConfigurar ? $this->grade($cadeado, $tenant) : [],
            pedidosPendentes: $podeConfigurar && $cadeado !== null ? $this->pendentes($pasta) : [],
        );
    }

    /**
     * A tela "Pasta trancada" só é para quem veria a pasta se ela estivesse livre: quem não veria nem assim
     * segue o caminho de sempre (o pedido de acesso genérico), sem saber que há um cadeado.
     */
    public function mostraATelaTrancada(Pasta $pasta, User $pessoa, CadeadoNaTelaOutput $cadeado): bool
    {
        return $cadeado->oculto() && $this->acessoAPasta->veriaSemCadeado($pessoa, $pasta);
    }

    /** @return list<array{id: int, nome: string, funcao: string, nivel: string}> */
    private function grade(?CadeadoDaPasta $cadeado, Tenant $tenant): array
    {
        $grade = [];
        foreach ($this->equipe->doEscritorio($tenant) as $vinculo) {
            $pessoa  = $vinculo->getUser();
            $grade[] = [
                'id'     => (int) $pessoa->getId(),
                'nome'   => (string) ($pessoa->getFullName() ?? $pessoa->getEmail()),
                'funcao' => EquipeDoCadeado::funcao($vinculo),
                'nivel'  => self::nivelInicial($cadeado, $pessoa)->value,
            ];
        }

        return $grade;
    }

    private static function nivelInicial(?CadeadoDaPasta $cadeado, User $pessoa): NivelDeAcesso
    {
        if ($cadeado === null) {
            return NivelDeAcesso::Editar;
        }

        return $cadeado->getModo() === ModoDoCadeado::Todos ? NivelDeAcesso::Oculto : $cadeado->nivelDe($pessoa);
    }

    /** @return list<array{id: int, nome: string, nivel: string, motivo: ?string, hora: string}> */
    private function pendentes(Pasta $pasta): array
    {
        return array_map(static fn (PedidoDeAcessoAPasta $p): array => [
            'id'     => (int) $p->getId(),
            'nome'   => (string) ($p->getSolicitante()->getFullName() ?? $p->getSolicitante()->getEmail()),
            'nivel'  => $p->getNivelPedido()->verbo(),
            'motivo' => $p->getMotivo(),
            'hora'   => $p->getPedidoEm()->format('d/m H:i'),
        ], $this->pedidos->pendentesDaPasta($pasta));
    }
}
