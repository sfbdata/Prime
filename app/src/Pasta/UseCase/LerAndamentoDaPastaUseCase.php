<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AndamentoDaPastaOutput;
use App\Pasta\DTO\LeituraDoAndamentoDaPastaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * Ler o andamento da pasta em fases, sem mudar nada (`docs/specs/mcp-remoto-oauth.md`, 6.4, `ler_pasta` parte
 * `andamento`; pedido A).
 *
 * Quem: alguém que vê a pasta e quer saber em que pé ela está (hoje, pelo conector de I.A.; a tela continua montando a
 * trilha no `PastaController`). O quê: a via, a trilha de fases com nome e número, a fase atual, as datas, as puladas,
 * se é estimada ou gravada, os nomes de etapa do "+" e as sugestões de próxima fase. Não existia rota só de leitura do
 * andamento (inventário 0.A).
 *
 * Regras:
 *  - a pasta é do escritório `$tenant` (senão {@see PastaDeOutroEscritorioException}) e quem lê pode vê-la, com o
 *    cadeado ({@see AcessoAPasta::podeVer()}; senão {@see SemPermissaoParaVerPastaException});
 *  - os mesmos dados de {@see AndamentoDaPastaOutput::de()}: {@see AndamentoDaPasta::estado()}, `rotulos()` e
 *    `sugestoes()`;
 *  - sugestão que cita o Push ou o Financeiro sai quando a aba de origem está oculta para quem lê (decisão 4 do PR 6);
 *    com o Push oculto, as publicações nem são lidas;
 *  - com a aba Dados oculta, a contagem de registros por fase não sai (o Registro mora na aba Dados);
 *  - nada é gravado: nenhum `marcar()`, nenhum flush.
 */
final class LerAndamentoDaPastaUseCase
{
    /** O ícone da sugestão diz de onde ela veio ({@see AndamentoDaPasta::sugestoes()}): a aba de origem. */
    private const ABA_DA_SUGESTAO = [
        'bi-broadcast' => AbaDaPasta::Push,
        'bi-pen' => AbaDaPasta::Financeiro,
    ];

    public function __construct(
        private readonly AndamentoDaPasta $andamento,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly VisibilidadeDasAbasDaPasta $abas,
    ) {
    }

    /**
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaVerPastaException
     */
    public function executar(Pasta $pasta, User $quem, Tenant $tenant): LeituraDoAndamentoDaPastaOutput
    {
        $this->exigirLeitura($pasta, $quem, $tenant);
        $ocultas = $this->abas->abasOcultasPara($pasta, $quem);

        $estado = $this->andamento->estado($pasta, $tenant);
        $publicacoes = \in_array(AbaDaPasta::Push, $ocultas, true) ? [] : null;
        $sugestoes = self::semAbaOculta($this->andamento->sugestoes($pasta, $tenant, $estado, $publicacoes), $ocultas);
        $mensagens = \in_array(AbaDaPasta::Dados, $ocultas, true) ? [] : $pasta->getMensagens();

        $tela = AndamentoDaPastaOutput::de($estado, $mensagens, $sugestoes, $this->andamento->rotulos($pasta, $tenant));

        return LeituraDoAndamentoDaPastaOutput::de($estado, $tela);
    }

    private function exigirLeitura(Pasta $pasta, User $quem, Tenant $tenant): void
    {
        $doEscritorio = $pasta->getTenant() === $tenant
            || ($tenant->getId() !== null && $pasta->getTenant()?->getId() === $tenant->getId());
        if (!$doEscritorio) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeVer($quem, $pasta)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }
    }

    /**
     * @param list<array{nome: string, indice: int, motivo: string, icone: string}> $sugestoes
     * @param list<AbaDaPasta>                                                     $ocultas
     *
     * @return list<array{nome: string, indice: int, motivo: string, icone: string}>
     */
    private static function semAbaOculta(array $sugestoes, array $ocultas): array
    {
        return array_values(array_filter(
            $sugestoes,
            static fn (array $s): bool => !\in_array(self::ABA_DA_SUGESTAO[$s['icone']] ?? null, $ocultas, true),
        ));
    }
}
