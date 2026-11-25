<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

/**
 * As regras de quem pode receber campanha e para onde vai a resposta (spec §8.5). Decisões provisórias do
 * coordenador (§8.8, itens 2 e o reply-to), todas a confirmar com o dono: mudam aqui, num lugar só.
 */
final class PoliticaDeDestinatarios
{
    /** @param string|null $replyTo o reply-to das campanhas; null (o padrão, decisão provisória) = o da plataforma */
    public function __construct(private readonly ?string $replyTo = null)
    {
    }

    /**
     * B1 restritivo: no CSV e no Individual só entra usuário ativo cadastrado ou endereço confirmado na lista
     * `comercial_campanhas`; os demais saem ("· J sem autorização fora da lista") e não são gravados. Reaplicado a
     * cada execução da recorrente.
     */
    public function exigirAutorizacaoEmCsvEIndividual(): bool
    {
        return true;
    }

    /**
     * "Contatos salvos" (a base do Master): o B1 acima NÃO se aplica. Decisão do Samuel de 25/11/2026 (opção 1, convite
     * profissional por legítimo interesse): o contato importado que nunca se cadastrou nem confirmou a lista pode receber
     * o convite. Em troca: link de descadastro e List-Unsubscribe em todo e-mail, descadastrado nunca mais recebe, intervalo
     * mínimo entre convites ({@see IntervaloDosConvites}) e a base guarda a origem de cada contato. CSV avulso e Individual
     * continuam com o B1: para convidar quem não autorizou, o Master importa antes para Contatos salvos.
     */
    public function dispensarAutorizacaoNosContatosSalvos(): bool
    {
        return true;
    }

    /** O reply-to das campanhas: null = o padrão da plataforma (`MAILER_REPLY_TO`, como na 1a). */
    public function replyToDaCampanha(): ?string
    {
        return $this->replyTo;
    }
}
