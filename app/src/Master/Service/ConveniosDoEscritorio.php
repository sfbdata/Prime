<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\DTO\ConvenioVigente;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use Psr\Clock\ClockInterface;

/**
 * A única porta de leitura dos convênios de um escritório, para telas, cobrança e espaço (spec docs/specs/convenios.md,
 * §3.4). Decide nesta ordem:
 *
 * 1. vínculos abertos do escritório cujo convênio está ligado e dentro da validade;
 * 2. escritório sem vínculo nenhum gravado (nem aberto nem fechado): o convênio OAB vale implícito. É como o sistema
 *    sempre foi, e vale até a sincronização do PR 3 gravar os vínculos (decisão de implementação de 24/11);
 * 3. no convênio OAB, a exceção do estado do escritório em `convenio_uf`: `sem` tira o convênio, `dif` o mantém com o
 *    valor diferenciado (só para a receita estimada);
 * 4. chave geral `convenio.ativo` desligada ({@see ChaveDoConvenio}): sai tudo o que é só exibição (gratuidade); um
 *    benefício real já vinculado (desconto, espaço) segue até o Master desligar o convênio (§6.1).
 */
final class ConveniosDoEscritorio
{
    public function __construct(
        private readonly ConvenioRepository $convenios,
        private readonly ConvenioVinculoRepository $vinculos,
        private readonly ConvenioDaUfRepository $excecoesPorUf,
        private readonly ChaveDoConvenio $chave,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** A chave geral: desligada, nenhum texto, selo ou rótulo de convênio aparece (§6.1). */
    public function exibicaoLigada(): bool
    {
        return $this->chave->ativo();
    }

    /** @return list<ConvenioVigente> */
    public function doTenant(int $tenantId, ?string $uf): array
    {
        return $this->dosTenants([$tenantId => $uf])[$tenantId];
    }

    /**
     * Em lote, para o painel do Master: quatro consultas para todas as contas, não quatro por conta.
     *
     * @param array<int, ?string> $ufPorTenant tenantId => UF do escritório (null sem estado válido)
     *
     * @return array<int, list<ConvenioVigente>>
     */
    public function dosTenants(array $ufPorTenant): array
    {
        $hoje = $this->relogio->now();
        $ids = array_keys($ufPorTenant);
        $abertos = $this->vinculos->abertosDosTenants($ids);
        $comVinculo = array_flip($this->vinculos->tenantsComVinculo($ids));
        $oab = $this->convenios->porChave(Convenio::CHAVE_OAB);
        $excecoes = $this->excecoesPorUf->todosPorUf();
        $chaveLigada = $this->chave->ativo();

        $saida = [];
        foreach ($ufPorTenant as $tenantId => $uf) {
            $convenios = array_map(static fn ($v): Convenio => $v->getConvenio(), $abertos[$tenantId] ?? []);
            $implicito = !isset($comVinculo[$tenantId]) && $oab !== null;
            if ($implicito) {
                $convenios[] = $oab;
            }
            $saida[$tenantId] = $this->vigentes($convenios, $implicito, $uf !== null ? ($excecoes[$uf] ?? null) : null, $hoje, $chaveLigada);
        }

        return $saida;
    }

    /**
     * @param list<Convenio> $convenios
     *
     * @return list<ConvenioVigente>
     */
    private function vigentes(array $convenios, bool $oabImplicito, ?ConvenioDaUf $excecao, \DateTimeImmutable $hoje, bool $chaveLigada): array
    {
        $vigentes = [];
        foreach ($convenios as $convenio) {
            if (!$convenio->vigenteEm($hoje) || (!$chaveLigada && !$convenio->getBeneficio()->ehReal())) {
                continue;
            }
            if (!$convenio->ehOab()) {
                $vigentes[] = ConvenioVigente::de($convenio, false);
                continue;
            }
            if ($excecao?->getModo() === ConvenioDaUf::MODO_SEM) {
                continue;
            }
            $vigentes[] = ConvenioVigente::de($convenio, $oabImplicito, $excecao);
        }

        return $vigentes;
    }
}
