<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;

/**
 * A saída que veio de um registro de esquecimento, e não de uma batida (spec `ponto-esquecimento-nao-trava.md` R4,
 * risco ALTO). A interjornada medida a partir dela não recusa a entrada seguinte: vira aviso. A saída batida
 * normalmente continua recusando.
 *
 * Sem coluna nova: os caminhos do admin que gravam a batida de um esquecimento gravam uma observação fixa (a aprovação,
 * individual ou "aprovar todos", a de `RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO`; o lançamento do admin em
 * lote, a `OBSERVACAO_LANCAMENTO_DO_ADMIN` abaixo), e a justificativa abonada de
 * esquecimento do mesmo dia, da mesma batida e da mesma hora fica gravada ao lado. Valem as duas juntas: a observação
 * sozinha poderia ser digitada no lançamento manual, e a justificativa sozinha não diz qual batida ela gerou.
 *
 * Desde a E10 (plano-e10-ponto §1), o esquecimento abonado entra como inclusão em `ponto_ajuste`, ligada à
 * justificativa: a batida efetiva traz o ajuste, e é ele que diz de onde a saída veio. O caminho da observação fica
 * para as saídas gravadas antes disso.
 */
final class SaidaDeEsquecimento
{
    /** A observação que o lançamento do admin (justificativa de esquecimento já abonada) grava na batida. */
    public const OBSERVACAO_LANCAMENTO_DO_ADMIN = 'Criado pelo administrador via justificativa (Esquecimento de Registro)';

    /** O aviso da entrada que passou apesar da interjornada (R4): o dia e a hora da saída e o mínimo em horas. */
    public const AVISO_INTERJORNADA = 'A entrada foi registrada. O descanso desde a saída de %s às %s, informada por registro de esquecimento, ficou abaixo da interjornada mínima de %dh.';

    public function __construct(
        private readonly JustificativaPontoRepository $justificativas,
    ) {
    }

    public function veioDeEsquecimento(MarcacaoDoPonto $saida): bool
    {
        if ($saida->getTipo() !== RegistroPonto::TIPO_SAIDA) {
            return false;
        }
        $ajuste = $saida->ajuste();
        if ($ajuste !== null) {
            // A inclusão do esquecimento abonado é a única cabeça com justificativa que ainda está na conta (a
            // desconsideração da análise tira a batida). Ajustada depois pelo gestor, a saída passa a ser dele.
            return $ajuste->tipo === TipoDoAjuste::Inclusao && $ajuste->justificativaId !== null;
        }

        return $saida instanceof RegistroPonto && $this->veioDaObservacao($saida);
    }

    /** As saídas gravadas antes da E10: a observação fixa e a justificativa abonada do mesmo dia, batida e hora. */
    private function veioDaObservacao(RegistroPonto $saida): bool
    {
        $user = $saida->getUser();
        $tenant = $saida->getTenant();
        $quando = $saida->getDataHora();
        if ($saida->getTipo() !== RegistroPonto::TIPO_SAIDA || $user === null || $tenant === null || $quando === null) {
            return false;
        }
        if (!in_array($saida->getObservacao(), [RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO, self::OBSERVACAO_LANCAMENTO_DO_ADMIN], true)) {
            return false;
        }

        return $this->justificativas->existeEsquecimentoAbonado(
            $user,
            $tenant,
            \DateTimeImmutable::createFromInterface($quando),
            RegistroPonto::TIPO_SAIDA,
        );
    }
}
