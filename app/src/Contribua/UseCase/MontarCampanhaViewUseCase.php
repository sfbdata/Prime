<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\CampanhaView;
use App\Contribua\Entity\Campanha;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Service\CampanhaVigente;
use App\Contribua\Service\FormatoDaCampanha;
use App\Entity\Auth\User;

/**
 * A campanha como o usuário logado a enxerga (aba do topo, faixa, FAQ): o que mostrar e com quais textos.
 * Null quando não existe campanha. Quem decide o que o Master vê a mais (prévia) é `ehMaster`.
 */
final class MontarCampanhaViewUseCase
{
    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly CampanhaParticipacaoRepository $participacoes,
    ) {
    }

    public function executar(User $usuario, bool $ehMaster): ?CampanhaView
    {
        $campanha = $this->vigente->campanha();
        if ($campanha === null) {
            return null;
        }

        return $this->montar($campanha, $this->vigente->estado($campanha), $ehMaster, $this->participacoes->daPessoa($campanha, $usuario) !== null);
    }

    public function montar(Campanha $campanha, EstadoDaCampanha $estado, bool $ehMaster, bool $temParticipacao): CampanhaView
    {
        $preco = FormatoDaCampanha::reais($campanha->getPrecoCentavos());
        $fim = FormatoDaCampanha::data($campanha->getFim());
        $inicio = FormatoDaCampanha::data($campanha->getInicio());
        $aoPublico = $estado->aoPublico();
        $mostrarFaq = $aoPublico;

        return new CampanhaView(
            nome: $campanha->getNome(),
            chamada: $campanha->getChamada(),
            chamadaSelo: mb_strtoupper($campanha->getChamada()),
            chamadaAba: mb_strtoupper(implode(' ', \array_slice(explode(' ', $campanha->getChamada()), 0, 2))),
            preco: $preco,
            devolve: FormatoDaCampanha::reais($campanha->valorDaDevolucaoCentavos()),
            multTx: FormatoDaCampanha::multiplicador($campanha->getMultiplicador()),
            prazoPedido: $campanha->getPrazoPedidoDias(),
            prazoPagto: $campanha->getPrazoPagamentoDiasUteis(),
            inicio: $inicio,
            fim: $fim,
            estado: $estado->value,
            ehMaster: $ehMaster,
            statusTexto: match ($estado) {
                EstadoDaCampanha::Ativa => 'Inscrições abertas até ' . $fim,
                EstadoDaCampanha::Esgotada => 'Vagas esgotadas',
                EstadoDaCampanha::Agendada => 'Prévia do Master · começa em ' . $inicio,
                EstadoDaCampanha::Encerrada => 'Prévia do Master · encerrada em ' . $fim,
                EstadoDaCampanha::Desligada => '',
            },
            mostrarAba: $campanha->isAbaNoTopo() && ($aoPublico || ($ehMaster && $estado !== EstadoDaCampanha::Ativa)),
            abaPrevia: !$aoPublico,
            abaTitulo: $aoPublico
                ? $campanha->getChamada() . ': Desafio ' . $preco
                : 'Prévia do Master: campanha ' . $estado->value . ' (só você vê)',
            mostrarFaixa: $aoPublico || ($ehMaster && $estado !== EstadoDaCampanha::Desligada),
            mostrarFaq: $mostrarFaq,
            faqPago: $mostrarFaq
                ? ' Durante a campanha ' . $campanha->getNome() . ', existe também a inscrição opcional no Desafio, de ' . $preco . ', com devolução de ' . FormatoDaCampanha::reais($campanha->valorDaDevolucaoCentavos()) . ' se você não gostar.'
                : '',
            botao: $temParticipacao ? 'Minha participação' : 'Conhecer o Desafio',
        );
    }
}
