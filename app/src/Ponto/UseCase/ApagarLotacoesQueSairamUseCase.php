<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\FeriadoRepository;
use App\Repository\LotacaoRepository;

/**
 * Apagar lotação na aba "Lotações" da edição do escritório (`TenantController::edit`, formulário `TenantLotacoesType`)
 * com a regra da vigência (spec `docs/specs/ponto-desenho-0811.md`, E6: decisão do Coordenador de 09/11/2026). A
 * lotação que tem feriado não é apagada: apagá-la mudaria os meses passados (o feriado dela deixaria de valer para quem
 * a tinha). Ela fica no escritório, e o resto do envio (renomear, criar, apagar as outras) continua valendo. A sem
 * feriado sai como antes, mesmo se estiver no histórico de lotações (`lotacao_vigencia`): as versões dela ficam sem
 * lotação (SET NULL), e lotação sem feriado e "sem lotação" dão o mesmo resultado no Ponto.
 *
 * Quem chama: o controller, DEPOIS do `handleRequest` e ANTES do flush; não dá flush. O formulário renomeia as próprias
 * lotações, mas não escreve a coleção do escritório (o Symfony não acha `addLotacao` pelo singular de "lotacoes"): quem
 * tira da coleção a que sumiu da tela é este caso de uso, e o `orphanRemoval` de `Tenant::$lotacoes` a apaga no flush
 * (o laço antigo do controller fazia o mesmo com `remove()`). A recusada fica na coleção; se algum caminho já a tirou,
 * volta, o que cancela a remoção do órfão. O banco é a segunda defesa: a chave de `feriado` para a lotação é NO
 * ACTION.
 */
final class ApagarLotacoesQueSairamUseCase
{
    public function __construct(
        private readonly LotacaoRepository $lotacoes,
        private readonly FeriadoRepository $feriados,
    ) {
    }

    /**
     * @param list<int> $idsQueFicam as lotações que continuam na tela
     *
     * @return list<Lotacao> as recusadas (com feriado), que continuam no escritório
     */
    public function executar(Tenant $escritorio, array $idsQueFicam): array
    {
        $comFeriado = $this->feriados->idsDasLotacoesComFeriado($escritorio);
        $recusadas = [];
        foreach ($this->lotacoes->findBy(['tenant' => $escritorio]) as $lotacao) {
            if (\in_array($lotacao->getId(), $idsQueFicam, true)) {
                continue;
            }
            if (\in_array($lotacao->getId(), $comFeriado, true)) {
                $escritorio->addLotacao($lotacao);
                $recusadas[] = $lotacao;

                continue;
            }
            $escritorio->removeLotacao($lotacao);
        }

        return $recusadas;
    }
}
