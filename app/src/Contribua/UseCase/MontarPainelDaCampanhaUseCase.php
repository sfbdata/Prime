<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\LinhaAguardandoDaCampanhaView;
use App\Contribua\DTO\LinhaDeDevolucaoDaCampanhaView;
use App\Contribua\DTO\PainelDaCampanhaView;
use App\Contribua\Repository\CampanhaDevolucaoRepository;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Service\CampanhaVigente;
use App\Contribua\Service\CofreDaChavePixDaCampanha;
use App\Contribua\Service\FormatoDaCampanha;
use App\Contribua\Service\RegrasDaCampanha;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** O painel da campanha no Contribua, só para o Master (quem chama garante o papel). Null sem campanha. */
final class MontarPainelDaCampanhaUseCase
{
    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly CampanhaParticipacaoRepository $participacoes,
        private readonly CampanhaDevolucaoRepository $devolucoes,
        private readonly CofreDaChavePixDaCampanha $cofre,
        private readonly RegrasDaCampanha $regras,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(): ?PainelDaCampanhaView
    {
        $campanha = $this->vigente->campanha();
        if ($campanha === null) {
            return null;
        }
        $estado = $this->vigente->estado($campanha);
        $devolve = FormatoDaCampanha::reais($campanha->valorDaDevolucaoCentavos());
        $vagas = $campanha->getVagas();

        $pedidos = $this->devolucoes->daCampanha($campanha);
        $pendentes = \count(array_filter($pedidos, static fn ($d): bool => $d->isPendente()));
        $devolvidos = \count($pedidos) - $pendentes;

        return new PainelDaCampanhaView(
            nome: $campanha->getNome(),
            chamada: $campanha->getChamada(),
            preco: number_format($campanha->getPrecoCentavos() / 100, 2, '.', ''),
            multiplicador: $campanha->getMultiplicador(),
            inicio: $campanha->getInicio()->format('Y-m-d'),
            fim: $campanha->getFim()->format('Y-m-d'),
            prazoPedido: $campanha->getPrazoPedidoDias(),
            prazoPagto: $campanha->getPrazoPagamentoDiasUteis(),
            vagas: $vagas,
            abaNoTopo: $campanha->isAbaNoTopo(),
            mostrarContador: $campanha->isMostrarContador(),
            ligada: $campanha->isLigada(),
            beneficiosTexto: implode("\n", $campanha->getBeneficios()),
            estadoRotulo: $estado->rotulo(),
            estadoCor: $estado->cor(),
            custo: $vagas > 0
                ? FormatoDaCampanha::reais($vagas * $campanha->valorDaDevolucaoCentavos()) . ' (' . $vagas . ' vagas × ' . $devolve . '), se todos pedirem'
                : $devolve . ' por participante que pedir (sem limite de vagas definido)',
            pedidosResumo: $pedidos === [] ? 'nenhum' : $pendentes . ' pendente(s), ' . $devolvidos . ' devolvido(s)',
            regras: $this->regras->gerar($campanha),
            devolucoes: array_map(fn ($d): LinhaDeDevolucaoDaCampanhaView => new LinhaDeDevolucaoDaCampanhaView(
                id: (int) $d->getId(),
                nome: $d->getParticipacao()->getNome(),
                oab: $d->getParticipacao()->getOab(),
                valor: FormatoDaCampanha::reais($d->getValorCentavos()),
                pix: $this->cofre->decifrar($d->getChavePixCifrada()) ?? '(chave ilegível)',
                em: FormatoDaCampanha::dataDoInstante($d->getPedidaEm()),
                motivo: $d->getMotivo(),
                pendente: $d->isPendente(),
            ), $pedidos),
            aguardando: array_map(static fn ($p): LinhaAguardandoDaCampanhaView => new LinhaAguardandoDaCampanhaView(
                id: (int) $p->getId(),
                nome: $p->getNome(),
                oab: $p->getOab(),
                cpfFinal: $p->getCpfFinal(),
                valor: FormatoDaCampanha::reais($p->getValorCentavos()),
                txid: (string) $p->getTxid(),
                em: FormatoDaCampanha::dataDoInstante($p->getCriadaEm()),
            ), $this->participacoes->aguardandoPagamento($campanha)),
            urls: [
                'configurar' => $this->urls->generate('contribua_campanha_configurar'),
                'padrao' => $this->urls->generate('contribua_campanha_padrao'),
                'confirmar' => $this->urls->generate('contribua_campanha_confirmar', ['id' => 0]),
                'devolvida' => $this->urls->generate('contribua_campanha_devolvida', ['id' => 0]),
            ],
        );
    }
}
