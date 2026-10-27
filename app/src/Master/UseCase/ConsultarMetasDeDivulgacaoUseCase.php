<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\MetasDeDivulgacaoOutput;
use App\Master\Entity\MetaDeDivulgacao;
use App\Master\Fontes\ClienteIbge;
use App\Master\Fontes\ClienteQuadroOab;
use App\Master\Fontes\FontesPublicas;
use App\Master\Repository\MetaDeDivulgacaoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Lê as Metas de divulgação para o bloco da Visão geral do /master (desenho 05 Perfis 1.2 VS 25.10.26).
 *
 * Quem dispara: o Master, ao abrir a Visão geral (o controller exige ROLE_SUPER_ADMIN).
 * Fluxo principal: devolve a configuração gravada (ou os padrões) e os advogados por estado da tabela da OAB. Com a
 * atualização automática ligada e vencida (frequência desde a última), troca o mercado pelo total da tabela da OAB,
 * a não ser que o Master o tenha fixado à mão, e registra a data (o `automatico` do desenho, que no protótipo rodava
 * no navegador). É a única gravação desta leitura; sem a linha, o primeiro acesso a cria já atualizada.
 * Auditoria: MetaDeDivulgacao é Auditavel; a gravação roda no escopo de plataforma.
 */
final class ConsultarMetasDeDivulgacaoUseCase
{
    public function __construct(
        private readonly MetaDeDivulgacaoRepository $metas,
        private readonly FontesPublicas $fontes,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(): MetasDeDivulgacaoOutput
    {
        $meta = $this->metas->buscarUnica() ?? new MetaDeDivulgacao();
        $advogados = $this->advogadosPorUf();

        $agora = $this->relogio->now();
        if ($meta->atualizacaoDevida($agora)) {
            $meta->registrarAtualizacaoAutomatica(array_sum($advogados), $agora);
            $meta->registrarAlteracao($meta->getAlteradoPor(), $agora);
            $this->escopoDePlataforma->executar(fn () => $this->metas->salvar($meta, flush: true));
        }

        return new MetasDeDivulgacaoOutput($meta, $advogados, ClienteQuadroOab::URL);
    }

    /** @return array<string, int> sigla => advogados regulares (só as UFs com número na tabela da OAB) */
    private function advogadosPorUf(): array
    {
        $porCodigo = (array) ($this->fontes->resumo()['uf'] ?? []);
        $porSigla = [];
        foreach (ClienteIbge::CODIGO_UF as $sigla => $codigo) {
            $adv = $porCodigo[(string) $codigo]['adv'] ?? null;
            if ($adv !== null) {
                $porSigla[$sigla] = (int) $adv;
            }
        }

        return $porSigla;
    }
}
