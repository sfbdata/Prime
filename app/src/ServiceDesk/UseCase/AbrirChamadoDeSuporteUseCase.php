<?php

declare(strict_types=1);

namespace App\ServiceDesk\UseCase;

use App\ServiceDesk\DTO\AbrirChamadoInput;
use App\ServiceDesk\DTO\ChamadoAbertoOutput;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Entity\HistoricoDoChamado;
use App\ServiceDesk\Exception\SaldoInsuficiente;
use App\ServiceDesk\Repository\ChamadoDeSuporteRepository;
use App\ServiceDesk\Repository\HistoricoDoChamadoRepository;
use App\ServiceDesk\Repository\PrecoDoAtendimentoRepository;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;
use App\ServiceDesk\Service\TransacaoDoRazao;

/**
 * O escritório abre um chamado para a equipe técnica (desenho 12, "Abrir chamado"). Exige saldo de pelo
 * menos R$ 20,00. NÃO debita: o débito é da equipe, ao concluir. Com o saldo travado, confere o mínimo e
 * reserva o número, então dois pedidos juntos não repetem número nem passam do saldo.
 */
final class AbrirChamadoDeSuporteUseCase
{
    public function __construct(
        private readonly ChamadoDeSuporteRepository $chamados,
        private readonly HistoricoDoChamadoRepository $historicos,
        private readonly PrecoDoAtendimentoRepository $precos,
        private readonly SaldoDoEscritorioRepository $saldos,
        private readonly TransacaoDoRazao $transacao,
    ) {
    }

    public function executar(AbrirChamadoInput $input): ChamadoAbertoOutput
    {
        ChamadoDeSuporte::exigirPedidoValido($input->tipo, $input->assunto, $input->dataAgendada, $input->horaAgendada);
        $aberto = $this->transacao->emTransacao(fn (): ?ChamadoDeSuporte => $this->abrirComSaldoTravado($input));
        if (null === $aberto) {
            throw SaldoInsuficiente::paraAbrirChamado();
        }

        return new ChamadoAbertoOutput((int) $aberto->getId(), $aberto->getNumero());
    }

    private function abrirComSaldoTravado(AbrirChamadoInput $input): ?ChamadoDeSuporte
    {
        if (!$this->saldos->travarDoEscritorio($input->tenant)->permiteAbrirChamado()) {
            return null;
        }
        $chamado = ChamadoDeSuporte::abrir(
            $input->tenant,
            $this->chamados->proximoNumero($input->tenant),
            $input->solicitante,
            $input->tipo,
            $input->prioridade,
            $input->assunto,
            $input->descricao,
            $input->dataAgendada,
            $input->horaAgendada,
            $this->precos->doTipo($input->tipo)?->getValorCentavos(),
        );
        $this->chamados->salvar($chamado);
        $this->historicos->salvar(new HistoricoDoChamado($input->tenant, $chamado, mb_substr('Chamado aberto por ' . $this->nomeDe($input), 0, 255)), flush: true);

        return $chamado;
    }

    private function nomeDe(AbrirChamadoInput $input): string
    {
        $nome = trim((string) $input->solicitante?->getFullName());

        return '' !== $nome ? $nome : 'usuário';
    }
}
