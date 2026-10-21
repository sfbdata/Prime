<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoVaga;

/**
 * Quem vê e quem faz o quê nas vagas, como no desenho 06 (spec VS 18.10.26 §4.1):
 * - interno = Master e Equipe (`interno()`, l.718): vê todas (`vagasBase`, l.990) e o histórico;
 * - gere = Master, ou Equipe com `talentos` (l.961): publica e muda a situação;
 * - externo = Solicitante e Convidado (P1, decidida pelo desenho): vê as abertas e as que divulgou,
 *   divulga (`podeDivulgar: !int`, l.1113) e só edita a dele (pendente ou reprovada, l.1095).
 */
final class PoliticaDaVaga
{
    public function __construct(
        private readonly PoliticaDaDemanda $demandas,
    ) {
    }

    public function ehInterno(Contexto $ctx): bool
    {
        return $ctx->ehInterno();
    }

    /** O `gere` do desenho. */
    public function ehDaCentral(Contexto $ctx): bool
    {
        return $this->demandas->pode($ctx, 'talentos');
    }

    public function ehMaster(Contexto $ctx): bool
    {
        return $ctx->perfil === Perfil::Master && !$ctx->previa;
    }

    /** A aba Vagas aparece a quem vê a Central (o desenho não filtra a lista de abas por perfil, l.1105). */
    public function veAba(Contexto $ctx): bool
    {
        return !$this->demandas->abaBloqueada($ctx)
            && ($ctx->ehInterno() || $ctx->perfil === Perfil::Convidado || ($ctx->perfil === Perfil::Solicitante && $ctx->tenant !== null));
    }

    public function podeVer(Contexto $ctx, Vaga $v, \DateTimeImmutable $agora): bool
    {
        if (!$this->veAba($ctx)) {
            return false;
        }

        return $ctx->ehInterno() || $this->ehDono($ctx, $v) || $v->situacaoEm($agora) === SituacaoVaga::Aberta;
    }

    /** O dono externo: o escritório dele, ou ele mesmo quando divulgou sem escritório (Convidado). */
    public function ehDono(Contexto $ctx, Vaga $v): bool
    {
        if ($ctx->ehInterno() || $v->ehDaCentral()) {
            return false;
        }
        $escritorio = $v->getEscritorio();

        return $escritorio !== null
            ? $ctx->perfil === Perfil::Solicitante && $ctx->tenant !== null && $escritorio->getId() === $ctx->tenant->getId()
            : $v->getCriadaPor()?->getId() === $ctx->usuario->getId();
    }

    /** "Nova vaga" (`tlGere`). */
    public function podeCriar(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->veAba($ctx) && $this->ehDaCentral($ctx);
    }

    /** "Divulgar uma vaga" (`podeDivulgar: !int`). */
    public function podeDivulgar(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->veAba($ctx) && !$ctx->ehInterno();
    }

    /** As ações de situação são só da Central (o dono externo não tem nenhuma, l.1001 e l.1095). */
    public function podeExecutar(Contexto $ctx, Vaga $v, AcaoVaga $acao): bool
    {
        return !$ctx->previa && $this->veAba($ctx) && $this->ehDaCentral($ctx) && $acao->valePara($v->isOferta());
    }

    /** "Editar vaga" da Central; "Editar pedido" e "Corrigir e reenviar" do dono. */
    public function podeEditar(Contexto $ctx, Vaga $v): bool
    {
        if ($ctx->previa || !$this->veAba($ctx)) {
            return false;
        }

        return $this->ehDaCentral($ctx)
            || ($this->ehDono($ctx, $v) && in_array($v->getSituacao(), [SituacaoVaga::Pendente, SituacaoVaga::Reprovada], true));
    }

    public function podeExcluir(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->ehDaCentral($ctx);
    }

    /** "Divulgar vaga" e "Compartilhar vaga": qualquer um que vê a vaga aberta (l.1001, l.1113). */
    public function podeCompartilhar(Contexto $ctx, Vaga $v, \DateTimeImmutable $agora): bool
    {
        return !$ctx->previa && $this->podeVer($ctx, $v, $agora) && $v->situacaoEm($agora) === SituacaoVaga::Aberta;
    }

    /** Mudar o status de um candidato e enviar candidatos: só a Central com `talentos` (decisão 3 da §4.4). */
    public function podeGerirCandidatos(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->veAba($ctx) && $this->ehDaCentral($ctx);
    }

    /** "Enviar N ao escritório" (`enviarCands`, l.981): só na vaga com dono. */
    public function podeEnviarCandidatos(Contexto $ctx, Vaga $v): bool
    {
        return $this->podeGerirCandidatos($ctx) && $v->temDono();
    }

    /** A lista de candidatos (`candOn`, l.1003): o interno vê todos; o dono externo, só os enviados a ele. */
    public function veCandidatos(Contexto $ctx, Vaga $v): bool
    {
        return $ctx->ehInterno() || $this->ehDono($ctx, $v);
    }

    /**
     * As ações de situação da vaga, na situação de agora (o vencimento já contado), na ordem do menu.
     *
     * @return list<array{k: string, rot: string, ic: string, tipo: string, motivo: bool}>
     */
    public function botoes(Contexto $ctx, Vaga $v, \DateTimeImmutable $agora): array
    {
        $situacao = $v->situacaoEm($agora);
        $botoes = [];
        foreach (AcaoVaga::cases() as $acao) {
            if (in_array($situacao, $acao->origens(), true) && $this->podeExecutar($ctx, $v, $acao)) {
                [$rot, $ic, $tipo] = $acao->botao($v->isOferta(), $v->getDuracaoDaOferta());
                $botoes[] = ['k' => $acao->value, 'rot' => $rot, 'ic' => $ic, 'tipo' => $tipo, 'motivo' => $acao->pedeMotivo()];
            }
        }

        return $botoes;
    }
}
