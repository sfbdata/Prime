<?php

declare(strict_types=1);

namespace App\Termo\Enum;

/**
 * Onde o aceite dos Termos (e a ciência da Política) foi dado. Entra no índice único junto com (user, versao): a mesma
 * pessoa pode ter, da mesma versão, o aceite do cadastro e o do link de confirmação (D2 da spec
 * `cadastro-senha-no-link.md`), sem apagar nenhum.
 */
enum OrigemDoAceite: string
{
    /** O cadastro e as contas que nascem por convite (o padrão; os registros anteriores à coluna ficaram assim). */
    case Cadastro = 'cadastro';

    /** A página "Crie sua senha" do link de confirmação do e-mail. */
    case LinkConfirmacao = 'link_confirmacao';

    /**
     * O gate dos Termos: quando a versão vigente muda, ou quando o aceite do cadastro deixou de valer
     * (CadastroNaoRatificado).
     */
    case Reaceite = 'reaceite';

    /**
     * Aceite do cadastro de uma conta confirmada pelo código do "Esqueceu a senha?" (D2b da spec
     * `cadastro-senha-no-link.md`): quem cadastrou pode ter sido um terceiro, então o registro fica no histórico mas
     * não vale como aceite; na primeira entrada, a dona do e-mail passa pelo gate dos Termos.
     */
    case CadastroNaoRatificado = 'cadastro_nao_ratificado';
}
