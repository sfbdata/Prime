<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Exception\BatidaEmHorarioFuturoException;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * Grava a batida pedida por uma justificativa de esquecimento de registro que o admin abonou.
 *
 * Caminho único da aprovação individual e do "aprovar todos" (spec `fix-aprovar-todos-esquecimento.md`).
 *
 * ## Inclusão, não registro (E10, D18)
 *
 * A batida do esquecimento não entra mais em `registro_ponto`, que guarda só o que a pessoa bateu: ela entra como
 * INCLUSÃO em `ponto_ajuste` ({@see GravacaoDoAjusteDoPonto::incluir}), ligada à justificativa e com quem abonou como
 * autor. Assim a volta da justificativa para análise consegue desconsiderá-la (`AnalisarJustificativaUseCase::reverter`)
 * sem apagar nada. O evento "Batida do esquecimento abonado" da trilha (E9) sai da própria gravação, no mesmo COMMIT.
 *
 * Roda na transação de quem chama (`AnalisarJustificativaUseCase`); sem nenhuma ativa, abre uma
 * ({@see TrilhaDoPonto::emTransacao}). A gravação trava a linhagem da pessoa (4210) e dá `flush()`; quem chama trava a
 * pessoa antes do primeiro evento da trilha, para a ordem das travas não se inverter.
 *
 * O esquecimento só vale para a batida que falta (spec `ponto-esquecimento-nao-trava.md` R1): com uma batida do mesmo
 * tipo naquele dia, escritório e pessoa, `recusa()` devolve a mensagem de `BatidaJaRegistrada` e quem chama deixa o
 * pedido pendente, para o admin decidir. Por segurança, `__invoke` também não grava nesse caso. As recusas (outro
 * escritório, batida já registrada, horário futuro) saem antes de qualquer escrita.
 */
final class RegistrarBatidaDoEsquecimentoUseCase
{
    /** O motivo da inclusão do esquecimento abonado (e a observação das batidas gravadas antes da E10). */
    public const OBSERVACAO = 'Criado por aprovação de justificativa (Esquecimento de Registro)';

    public function __construct(
        private readonly HorarioFuturoDaBatida $horarioFuturo,
        private readonly BatidaJaRegistrada $batidaJaRegistrada,
        private readonly GravacaoDoAjusteDoPonto $gravacao,
        private readonly TrilhaDoPonto $trilha,
    ) {
    }

    /** A recusa da aprovação quando a batida pedida já existe no dia; null quando segue ou não é esquecimento. */
    public function recusa(JustificativaPonto $justificativa, Tenant $tenant): ?string
    {
        $user = $justificativa->getUser();
        $tipo = $justificativa->getTipoRegistroEsquecido();
        $dia = $justificativa->getData();
        if ($justificativa->getTipo() !== 'esquecimento_registro' || $user === null || $tipo === null || $dia === null) {
            return null;
        }

        return $this->batidaJaRegistrada->mensagem($user, $tenant, $tipo, $dia);
    }

    /**
     * Devolve a inclusão gravada, ou null quando a justificativa não é de esquecimento, falta tipo, hora ou data, ou a
     * batida pedida já existe no dia.
     *
     * @param User $quemAbonou o autor da inclusão e do evento da trilha
     *
     * @throws \DomainException                quando a justificativa é de outro escritório
     * @throws BatidaEmHorarioFuturoException quando a data e a hora pedidas ficam acima de agora
     */
    public function __invoke(JustificativaPonto $justificativa, Tenant $tenant, User $quemAbonou): ?PontoAjuste
    {
        if (!self::pedeBatida($justificativa)) {
            return null;
        }

        if ($justificativa->getTenant()?->getId() !== $tenant->getId()) {
            throw new \DomainException('A justificativa não pertence a este escritório.');
        }
        $pessoa = $justificativa->getUser() ?? throw new \LogicException('A justificativa do esquecimento não tem pessoa.');
        if ($this->recusa($justificativa, $tenant) !== null) {
            return null;   // a batida pedida já existe no dia: não grava a segunda (R1)
        }

        if ($this->pedeHorarioFuturo($justificativa)) {
            throw new BatidaEmHorarioFuturoException();
        }

        $tipo     = (string) $justificativa->getTipoRegistroEsquecido();
        $dataHora = new \DateTimeImmutable(
            $justificativa->getData()->format('Y-m-d') . ' '
            . $justificativa->getHoraRegistroEsquecido()->format('H:i:s')
        );

        return $this->trilha->emTransacao(
            fn (): PontoAjuste => $this->gravacao->incluir($tenant, $pessoa, $tipo, $dataHora, self::OBSERVACAO, $quemAbonou, $justificativa),
        );
    }

    /**
     * Recusa rápida para quem chama antes de abonar: o pedido de esquecimento cai acima de agora.
     */
    public function pedeHorarioFuturo(JustificativaPonto $justificativa): bool
    {
        return self::pedeBatida($justificativa)
            && $this->horarioFuturo->estaNoFuturo($justificativa->getData(), $justificativa->getHoraRegistroEsquecido());
    }

    private static function pedeBatida(JustificativaPonto $justificativa): bool
    {
        return $justificativa->getTipo() === 'esquecimento_registro'
            && $justificativa->getTipoRegistroEsquecido() !== null
            && $justificativa->getHoraRegistroEsquecido() !== null
            && $justificativa->getData() !== null;
    }
}
