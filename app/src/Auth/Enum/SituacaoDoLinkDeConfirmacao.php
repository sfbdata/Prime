<?php

declare(strict_types=1);

namespace App\Auth\Enum;

/**
 * O que a página do link de confirmação mostra (spec `cadastro-senha-no-link.md`). O link inválido ou vencido não é
 * um caso daqui: sai como \DomainException com o texto da tela.
 */
enum SituacaoDoLinkDeConfirmacao
{
    /** O e-mail ainda está a confirmar: a página pede a senha nova. */
    case Pendente;
    /** O e-mail já foi confirmado (outro clique, o código da senha ou quem perdeu a corrida): nada é trocado. */
    case JaConfirmado;
    /** Este pedido criou a senha e confirmou o e-mail. */
    case SenhaCriada;
}
