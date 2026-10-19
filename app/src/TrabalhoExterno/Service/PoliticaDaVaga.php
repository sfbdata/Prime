<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoVaga;

/**
 * Quem vê e quem faz o quê nas vagas (spec VS 18.10.26 §4.1). Convenção até o zip: a regra do
 * desenho estava no código perdido. A Central é o Master e a Equipe com `talentos`; o escritório é
 * o Solicitante. O Convidado não vê vagas (a P1, quem é o candidato, ainda está aberta).
 */
final class PoliticaDaVaga
{
    public function __construct(
        private readonly PoliticaDaDemanda $demandas,
    ) {
    }

    public function ehDaCentral(Contexto $ctx): bool
    {
        return $this->demandas->pode($ctx, 'talentos');
    }

    /** A aba Vagas aparece para este perfil. */
    public function veAba(Contexto $ctx): bool
    {
        if ($this->demandas->abaBloqueada($ctx)) {
            return false;
        }

        return $this->ehDaCentral($ctx) || ($ctx->perfil === Perfil::Solicitante && $ctx->tenant !== null);
    }

    public function podeVer(Contexto $ctx, Vaga $v, \DateTimeImmutable $agora): bool
    {
        if (!$this->veAba($ctx)) {
            return false;
        }

        return $this->ehDaCentral($ctx) || $this->ehDono($ctx, $v) || $v->situacaoEm($agora) === SituacaoVaga::Aberta;
    }

    public function ehDono(Contexto $ctx, Vaga $v): bool
    {
        return $ctx->perfil === Perfil::Solicitante
            && $ctx->tenant !== null
            && $v->getEscritorio()?->getId() === $ctx->tenant->getId();
    }

    /** "Nova vaga" (`tlGere`): a Central publica direto. */
    public function podeCriar(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->veAba($ctx) && $this->ehDaCentral($ctx);
    }

    /** "Divulgar uma vaga" (`podeDivulgar`): o escritório envia para a Central aprovar. */
    public function podeDivulgar(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->veAba($ctx) && $ctx->perfil === Perfil::Solicitante && $ctx->tenant !== null;
    }

    public function podeExecutar(Contexto $ctx, Vaga $v, AcaoVaga $acao): bool
    {
        if ($ctx->previa || !$this->veAba($ctx)) {
            return false;
        }

        return $acao->ehDaCentral() ? $this->ehDaCentral($ctx) : ($this->ehDaCentral($ctx) || $this->ehDono($ctx, $v));
    }

    /**
     * Os botões da vaga, na situação de agora (o vencimento já contado).
     *
     * @return list<array{k: string, rot: string, ic: string, tipo: string, motivo: bool}>
     */
    public function botoes(Contexto $ctx, Vaga $v, \DateTimeImmutable $agora): array
    {
        $situacao = $v->situacaoEm($agora);
        $botoes = [];
        foreach (AcaoVaga::cases() as $acao) {
            if (in_array($situacao, $acao->origens(), true) && $this->podeExecutar($ctx, $v, $acao)) {
                [$rot, $ic, $tipo] = $acao->botao();
                $botoes[] = ['k' => $acao->value, 'rot' => $rot, 'ic' => $ic, 'tipo' => $tipo, 'motivo' => $acao->exigeMotivo()];
            }
        }

        return $botoes;
    }

    /** Contato de quem oferece: os campos marcados com o olho fechado só a Central e o dono veem. */
    public function veContatoOculto(Contexto $ctx, Vaga $v): bool
    {
        return $this->ehDaCentral($ctx) || $this->ehDono($ctx, $v);
    }
}
