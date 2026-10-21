<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\Perfil;

/**
 * Quem vê e quem faz o quê nos currículos (spec VS 18.10.26 §4.5, desenho 06):
 * - externo (Solicitante e Convidado): só o dele, "Meu currículo" (`modCvExt`, l.1109);
 * - interno (Master e Equipe): o banco inteiro, contato e arquivos inclusive (`modCvInt`);
 * - gerir (status, nota, CSV) = Master, ou Equipe com `talentos`;
 * - excluir o de outra pessoa: só o Master (l.1000); o dono exclui o dele;
 * - o interno não tem "Meu currículo" (decisão 18);
 * - na prévia do Master (`?como=`) nada se escreve.
 */
final class PoliticaDoCurriculo
{
    public function __construct(
        private readonly PoliticaDaVaga $vagas,
        private readonly PoliticaDaDemanda $demandas,
    ) {
    }

    /** A aba aparece a quem vê as vagas: é a mesma faixa de abas (l.1105). */
    public function veAba(Contexto $ctx): bool
    {
        return $this->vagas->veAba($ctx);
    }

    public function ehInterno(Contexto $ctx): bool
    {
        return $ctx->ehInterno();
    }

    public function ehMaster(Contexto $ctx): bool
    {
        return $ctx->perfil === Perfil::Master && !$ctx->previa;
    }

    /** Status, nota e CSV: o `gere` do desenho, nunca na prévia. */
    public function podeGerir(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->veAba($ctx) && $this->demandas->pode($ctx, 'talentos');
    }

    /** "Meu currículo" existe só para o externo. */
    public function temMeuCurriculo(Contexto $ctx): bool
    {
        return $this->veAba($ctx) && !$ctx->ehInterno();
    }

    public function podeEscreverOMeu(Contexto $ctx): bool
    {
        return !$ctx->previa && $this->temMeuCurriculo($ctx);
    }

    public function ehDono(Contexto $ctx, Curriculo $cv): bool
    {
        return !$ctx->ehInterno() && $cv->getUsuario()->getId() === $ctx->usuario->getId();
    }

    public function podeVer(Contexto $ctx, Curriculo $cv): bool
    {
        return $this->veAba($ctx) && ($ctx->ehInterno() || $this->ehDono($ctx, $cv));
    }

    public function podeEditar(Contexto $ctx, Curriculo $cv): bool
    {
        return $this->podeEscreverOMeu($ctx) && $this->ehDono($ctx, $cv);
    }

    /** O dono exclui o dele; o de outra pessoa, só o Master. */
    public function podeExcluir(Contexto $ctx, Curriculo $cv): bool
    {
        return $this->podeEditar($ctx, $cv) || ($this->ehMaster($ctx) && $this->veAba($ctx));
    }

    /** "NOTA INTERNA (SÓ MASTER E EQUIPE)" (l.311): nunca sai para o externo. */
    public function veNota(Contexto $ctx): bool
    {
        return $ctx->ehInterno();
    }
}
