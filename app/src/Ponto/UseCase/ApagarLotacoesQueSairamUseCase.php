<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use App\Repository\LotacaoRepository;

/**
 * Apagar lotação na aba "Lotações" da edição do escritório (`TenantController::edit`, formulário `TenantLotacoesType`)
 * com a regra da vigência (spec `docs/specs/ponto-desenho-0811.md`, E6: decisão do Coordenador de 09/11/2026). A
 * lotação que tem feriado ou versão no histórico de lotações do Ponto (`lotacao_vigencia`) não é apagada: apagá-la
 * mudaria os meses passados (o feriado dela deixaria de valer para quem a tinha). Ela volta ao escritório, e o resto do
 * envio (renomear, criar, apagar as outras) continua valendo. A sem nada sai como antes.
 *
 * Quem chama: o controller, DEPOIS do `handleRequest` e ANTES do flush; não dá flush. O formulário renomeia as próprias
 * lotações, mas não escreve a coleção do escritório (o Symfony não acha `addLotacao` pelo singular de "lotacoes"): quem
 * tira da coleção a que sumiu da tela é este caso de uso, e o `orphanRemoval` de `Tenant::$lotacoes` a apaga no flush
 * (o laço antigo do controller fazia o mesmo com `remove()`). A recusada fica na coleção; se algum caminho já a tirou,
 * volta, o que cancela a remoção do órfão. O banco é a segunda defesa: as chaves de `feriado` e de `lotacao_vigencia`
 * para a lotação são NO ACTION.
 */
final class ApagarLotacoesQueSairamUseCase
{
    public function __construct(
        private readonly LotacaoRepository $lotacoes,
        private readonly LotacaoComVigenciaRepository $versoes,
    ) {
    }

    /**
     * @param list<int> $idsQueFicam as lotações que continuam na tela
     *
     * @return list<Lotacao> as recusadas (com feriado ou histórico), que continuam no escritório
     */
    public function executar(Tenant $escritorio, array $idsQueFicam): array
    {
        $comHistorico = $this->versoes->idsDasLotacoesComHistorico($escritorio);
        $recusadas = [];
        foreach ($this->lotacoes->findBy(['tenant' => $escritorio]) as $lotacao) {
            if (\in_array($lotacao->getId(), $idsQueFicam, true)) {
                continue;
            }
            if (\in_array($lotacao->getId(), $comHistorico, true)) {
                $escritorio->addLotacao($lotacao);
                $recusadas[] = $lotacao;

                continue;
            }
            $escritorio->removeLotacao($lotacao);
        }

        return $recusadas;
    }
}
