<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;

/**
 * A pré-visualização do Master (spec §9): o seletor "Master · Solicitante · Equipe · Convidado" do
 * desenho, sem troca de identidade. Monta, para cada demanda, o contexto com que AQUELE perfil a
 * veria, marcado como prévia (a política nega toda escrita).
 *
 * A prévia parte SEMPRE das demandas que o próprio Master vê ({@see LocalizadorDeDemanda::visiveis},
 * todas menos rascunhos) e só aplica recortes por cima: nunca mostra o que o Master não veria.
 */
final class PreviaDePerfil
{
    private const PERFIS = [
        'solicitante' => Perfil::Solicitante,
        'equipe' => Perfil::Equipe,
        'convidado' => Perfil::Convidado,
    ];

    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
    ) {
    }

    /**
     * O perfil pedido, se o pedido vale: só o Master real pré-visualiza, e só os três outros perfis.
     * Qualquer outra combinação devolve null e a tela segue no perfil real de quem pediu.
     */
    public function perfilPedido(Contexto $real, ?string $como): ?Perfil
    {
        if ($real->perfil !== Perfil::Master || $real->previa || $como === null) {
            return null;
        }

        return self::PERFIS[$como] ?? null;
    }

    /** O contexto "de cabeçalho" do perfil pré-visualizado: configuração, bloqueio e aviso da tela. */
    public function contexto(Contexto $master, Perfil $como): Contexto
    {
        return new Contexto($master->usuario, $master->tenant, $como, $master->configuracao, null, true);
    }

    /**
     * As demandas da lista, cada uma com o contexto do perfil pré-visualizado.
     *
     * @return list<array{0: DemandaExterna, 1: Contexto}>
     */
    public function pares(Contexto $master, Perfil $como): array
    {
        $pares = [];
        foreach ($this->localizador->visiveis($master) as [$d]) {
            $ctx = $this->paraDemanda($master, $como, $d);
            if ($ctx !== null) {
                $pares[] = [$d, $ctx];
            }
        }

        return $pares;
    }

    /** O contexto com que o perfil pré-visualizado veria esta demanda; null se ele não a veria. */
    public function paraDemanda(Contexto $master, Perfil $como, DemandaExterna $d): ?Contexto
    {
        $cfg = $master->configuracao;
        $ctx = match ($como) {
            // O escritório DONO da demanda: é ele o Solicitante dela.
            Perfil::Solicitante => new Contexto($master->usuario, $d->getTenant(), Perfil::Solicitante, $cfg, null, true),
            Perfil::Equipe => new Contexto($master->usuario, $cfg->getSede() ?? $master->tenant, Perfil::Equipe, $cfg, null, true),
            // O convidado DAQUELE convite (permissões e corte do aceite dele); sem convite aceito, nada.
            Perfil::Convidado => $this->comoConvidado($master, $d),
            default => null,
        };

        return $ctx !== null && $this->politica->podeVer($ctx, $d) ? $ctx : null;
    }

    private function comoConvidado(Contexto $master, DemandaExterna $d): ?Contexto
    {
        $hoje = new \DateTimeImmutable('today');
        foreach ($d->getConvites() as $convite) {
            /** @var ConviteDemanda $convite */
            $convidado = $convite->getConvidado();
            if ($convidado !== null && $convite->vigente($hoje)) {
                return new Contexto($convidado, null, Perfil::Convidado, $master->configuracao, $convite, true);
            }
        }

        return null;
    }
}
