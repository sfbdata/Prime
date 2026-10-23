<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * A conta criada pelo cadastro com o e-mail ainda "a confirmar" não entra no sistema (spec
 * `cadastro-exige-confirmacao.md`). Aqui moram, num lugar só, os textos novos da frente e a chave da sessão que
 * guarda o id dessa conta para o "Reenviar o link de confirmação" público.
 *
 * A chave só é gravada por quem já provou ser a dona da conta neste navegador: o cadastro que acabou de criá-la, o
 * login com a senha certa (`UserChecker::checkPostAuth`) e a sessão já aberta que é encerrada
 * (`SessaoDeContaAConfirmarListener`). O reenvio público lê dali, nunca de um e-mail digitado.
 *
 * Num navegador compartilhado a chave não pode ficar para a próxima pessoa: ela guarda o momento e vale por
 * `VALIDADE` (30 minutos), e sai quando outra conta entra com sucesso (`EsquecerContaAConfirmarNoLoginListener`).
 */
final class ContaAConfirmar
{
    public const CHAVE_SESSAO = 'conta_a_confirmar';

    /** Quanto tempo a chave vale depois de gravada. */
    public const VALIDADE = '+30 minutes';

    /** Intenção do CSRF do reenvio público (formulário da tela de entrada e modal do cadastro). */
    public const CSRF = 'confirmacao_reenviar_publico';

    /** Recusa do login (formulário e lembrar-me) e da sessão encerrada; `{email}` é o da própria conta. */
    public const MENSAGEM_LOGIN = 'Confirme o seu e-mail para entrar. Enviamos o link para {email}.';

    /**
     * Modal do cadastro, estado "Confirme seu e-mail". O mesmo texto para o e-mail novo e para o que já tem conta: o
     * cadastro não diz qual é o caso (achado de auditoria interna F14; spec `seguranca-a-convites.md`, raiz E).
     */
    public const TITULO = 'Confirme seu e-mail';
    public const TEXTO_ENVIAMOS_ANTES = 'Se o e-mail';
    public const TEXTO_ENVIAMOS_DEPOIS = ' puder ser usado, você receberá as instruções nele. Clique no link para criar a sua senha e ativar sua conta e seu escritório. O link expira em 7 dias.';
    public const TEXTO_NAO_RECEBEU = 'Não recebeu? Verifique a caixa de spam ou peça um novo link.';
    public const BOTAO_REENVIAR = 'Reenviar o link de confirmação';
    public const LINK_LOGIN = 'Ir para o login';

    /**
     * Resposta do reenvio público quando a sessão não guarda conta nenhuma: não diz nada sobre conta alguma. A conta
     * a confirmar não tem senha utilizável (spec `cadastro-senha-no-link.md`): o caminho é o código da senha.
     */
    public const SEM_CONTA_NA_SESSAO = 'Para receber um novo acesso, use "Esqueceu a senha?": enviamos um código para o seu e-mail.';

    public static function mensagemDeLogin(string $email): string
    {
        return strtr(self::MENSAGEM_LOGIN, ['{email}' => $email]);
    }

    public function __construct(
        private readonly ClockInterface $relogio,
    ) {
    }

    public function guardar(SessionInterface $sessao, User $user): void
    {
        $sessao->set(self::CHAVE_SESSAO, ['id' => $user->getId(), 'em' => $this->relogio->now()->getTimestamp()]);
    }

    /** O id da conta guardada, se a chave é válida e não venceu; senão, esquece a chave e devolve null. */
    public function idNaSessao(SessionInterface $sessao): ?int
    {
        $valor = $sessao->get(self::CHAVE_SESSAO);
        if ($valor === null) {
            return null;
        }

        $id = is_array($valor) ? ($valor['id'] ?? null) : null;
        $em = is_array($valor) ? ($valor['em'] ?? null) : null;
        $agora = $this->relogio->now();
        if (!is_int($id) || $id <= 0 || !is_int($em) || $agora > $agora->setTimestamp($em)->modify(self::VALIDADE)) {
            self::esquecer($sessao);

            return null;
        }

        return $id;
    }

    public static function esquecer(SessionInterface $sessao): void
    {
        $sessao->remove(self::CHAVE_SESSAO);
    }
}
