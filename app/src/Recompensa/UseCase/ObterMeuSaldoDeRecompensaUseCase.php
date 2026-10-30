<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Recompensa\DTO\MeuSaldoDeRecompensaOutput;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;

/**
 * O saldo de recompensa de quem está logado, para o cartão do topo do Contribua (desenho 08 VS 30.10.26).
 *
 * Quem dispara: qualquer pessoa logada, ao abrir /contribua. Fluxo principal: a linha da pessoa no painel de Recompensas
 * do Master ({@see ConsultarPainelDeRecompensasUseCase::doIndicador()}), com as mesmas regras (carência, pasta,
 * antifraude, lançamentos e baixas), o cupom `BJ-<código>` do link curto (nasce aqui no primeiro acesso, como na
 * janela "Indicar o BlueJus") e os contadores. "Convites" é o total de indicações da pessoa (o mesmo de "SUAS
 * INDICAÇÕES" na janela, envios e cadastros pelo link) e "contas abertas" as que contam para ela no painel: as duas
 * saem da tabela de indicações, e o desenho faz "aguardando" = convites menos contas. Quem nunca indicou (ou foi apagado da lista) vê saldo zero, com o valor
 * e o mínimo da própria condição ou da regra geral.
 */
final class ObterMeuSaldoDeRecompensaUseCase
{
    public function __construct(
        private readonly ConsultarPainelDeRecompensasUseCase $painel,
        private readonly ProgramaDeRecompensaRepository $programas,
        private readonly CondicaoDeRecompensaRepository $condicoes,
        private readonly CodigoDeIndicacao $codigos,
        private readonly IndicacaoRepository $indicacoes,
    ) {
    }

    public function executar(User $user): MeuSaldoDeRecompensaOutput
    {
        $userId = (int) $user->getId();
        $programa = $this->programas->buscarUnico() ?? new ProgramaDeRecompensa();
        $cupom = 'BJ-' . $this->codigos->codigoDe($user);
        $linha = $this->painel->doIndicador($userId);
        $convites = $this->indicacoes->contarDoUsuario($userId);
        if ($linha !== null) {
            return new MeuSaldoDeRecompensaOutput(
                $linha->saldoCentavos,
                $linha->pagoCentavos,
                $linha->valorCentavos,
                $linha->minEscritorios,
                $linha->ativos,
                $linha->liberado,
                $programa->isExigePasta(),
                $cupom,
                $convites,
                $linha->contas,
            );
        }
        $condicao = $this->condicoes->buscarDoIndicador($userId);
        $minimo = $condicao?->getMinEscritorios() ?? $programa->getMinEscritorios();

        return new MeuSaldoDeRecompensaOutput(
            0,
            0,
            $condicao?->getValorCentavos() ?? $programa->getValorCentavos(),
            $minimo,
            0,
            $minimo <= 0,
            $programa->isExigePasta(),
            $cupom,
            $convites,
            0,
        );
    }
}
