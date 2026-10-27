<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Enum\StatusDoTermo;

/**
 * Quem faz o quê com o Termo de Voluntariado (spec `trabalho-externo-termo-voluntariado.md`, "Permissões"):
 * - gerar, enviar e ler o termo completo: quem gere o banco de talentos (Master, ou Equipe com `talentos`);
 * - o voluntário lê e aceita só o termo do PRÓPRIO currículo, e só aceita se estiver `enviado`;
 * - os demais internos veem apenas o status, na lista;
 * - a prévia do Master (`?como=`) não escreve.
 */
final class PoliticaDoTermo
{
    public function __construct(
        private readonly PoliticaDoCurriculo $curriculos,
    ) {
    }

    /** "Gerar termo de voluntariado" e "Enviar para aceite": só com o currículo aceitando voluntário. */
    public function podeGerar(Contexto $ctx, Curriculo $cv): bool
    {
        return $this->curriculos->podeGerir($ctx) && $cv->texto('vol') !== 'nao';
    }

    /** O termo completo (com CPF e RG): quem gere o banco, ou o dono do currículo. */
    public function podeVerCompleto(Contexto $ctx, TermoDeVoluntariado $termo): bool
    {
        return $this->curriculos->podeGerir($ctx) || ($this->curriculos->veAba($ctx) && $this->curriculos->ehDono($ctx, $termo->getCurriculo()));
    }

    public function podeAceitar(Contexto $ctx, TermoDeVoluntariado $termo): bool
    {
        return $termo->getStatus() === StatusDoTermo::Enviado
            && $this->curriculos->podeEscreverOMeu($ctx)
            && $this->curriculos->ehDono($ctx, $termo->getCurriculo());
    }
}
