<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * A conta criada pelo cadastro com o e-mail ainda "a confirmar" não entra no sistema (spec
 * `cadastro-exige-confirmacao.md`). Aqui moram, num lugar só, os textos novos da frente e a chave da sessão que
 * guarda o id dessa conta para o "Reenviar o link de confirmação" público.
 *
 * A chave só é gravada por quem já provou ser a dona da conta neste navegador: o cadastro que acabou de criá-la, o
 * login com a senha certa (`UserChecker::checkPostAuth`) e a sessão já aberta que é encerrada
 * (`SessaoDeContaAConfirmarListener`). O reenvio público lê dali, nunca de um e-mail digitado.
 */
final class ContaAConfirmar
{
    public const CHAVE_SESSAO = 'conta_a_confirmar';

    /** Intenção do CSRF do reenvio público (formulário da tela de entrada e modal do cadastro). */
    public const CSRF = 'confirmacao_reenviar_publico';

    /** Recusa do login (formulário e lembrar-me) e da sessão encerrada; `{email}` é o da própria conta. */
    public const MENSAGEM_LOGIN = 'Confirme o seu e-mail para entrar. Enviamos o link para {email}.';

    /** Modal do cadastro, estado "Confirme seu e-mail" (textos da tela antiga `verifique_email.html.twig`). */
    public const TITULO = 'Confirme seu e-mail';
    public const TEXTO_ENVIAMOS_ANTES = 'Enviamos um link de confirmação para';
    public const TEXTO_ENVIAMOS_DEPOIS = '. Clique nele para ativar sua conta e seu escritório. O link expira em 7 dias.';
    public const TEXTO_NAO_RECEBEU = 'Não recebeu? Verifique a caixa de spam ou peça um novo link.';
    public const BOTAO_REENVIAR = 'Reenviar o link de confirmação';
    public const LINK_LOGIN = 'Ir para o login';

    /** Resposta do reenvio público quando a sessão não guarda conta nenhuma: não diz nada sobre conta alguma. */
    public const SEM_CONTA_NA_SESSAO = 'Entre com o seu e-mail e a sua senha para pedir um novo link.';

    public static function mensagemDeLogin(string $email): string
    {
        return strtr(self::MENSAGEM_LOGIN, ['{email}' => $email]);
    }

    public static function guardar(SessionInterface $sessao, User $user): void
    {
        $sessao->set(self::CHAVE_SESSAO, $user->getId());
    }

    public static function idNaSessao(SessionInterface $sessao): ?int
    {
        $id = $sessao->get(self::CHAVE_SESSAO);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function esquecer(SessionInterface $sessao): void
    {
        $sessao->remove(self::CHAVE_SESSAO);
    }
}
