<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\IdentidadeDoFacebook;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * "Continuar com Facebook" sem conta (spec login-cupom-e-facebook, PR B): a identidade que o Facebook acabou de provar
 * espera na sessão até a pessoa completar o "Criar conta". O cadastro lê a identidade daqui, nunca do navegador. Vale
 * por `VALIDADE` e sai no "Usar outro e-mail", quando a conta é criada e quando outra conta entra.
 */
final class CadastroPeloFacebook
{
    public const CHAVE_SESSAO = 'cadastro_pelo_facebook';

    /** Curto de propósito: no navegador compartilhado, quem chega depois não vê o cartão de quem saiu da aba. */
    public const VALIDADE = '+15 minutes';

    /** Id da conta que ESTA sessão criou pelo Facebook: só o link aberto aqui mantém o `facebook_id` (pré-sequestro). */
    public const CHAVE_CONTA = 'cadastro_pelo_facebook_conta';

    public const EXPIROU = 'A conexão com o Facebook expirou. Clique em "Continuar com Facebook" de novo.';

    public function __construct(
        private readonly ClockInterface $relogio,
    ) {
    }

    public function guardar(SessionInterface $sessao, IdentidadeDoFacebook $identidade): void
    {
        $sessao->set(self::CHAVE_SESSAO, $identidade->paraSessao() + ['em' => $this->relogio->now()->getTimestamp()]);
    }

    /** A identidade guardada, se não venceu; senão, esquece a chave e devolve null. */
    public function ler(SessionInterface $sessao): ?IdentidadeDoFacebook
    {
        $valor = $sessao->get(self::CHAVE_SESSAO);
        if (null === $valor) {
            return null;
        }
        $em = is_array($valor) ? ($valor['em'] ?? null) : null;
        $agora = $this->relogio->now();
        $identidade = is_array($valor) ? IdentidadeDoFacebook::daSessao($valor) : null;
        if (null === $identidade || !is_int($em) || $agora > $agora->setTimestamp($em)->modify(self::VALIDADE)) {
            self::esquecer($sessao);

            return null;
        }

        return $identidade;
    }

    public static function marcarConta(SessionInterface $sessao, int $userId): void
    {
        $sessao->set(self::CHAVE_CONTA, $userId);
    }

    /** A conta criada pelo Facebook nesta sessão, ou null. */
    public static function contaDaSessao(SessionInterface $sessao): ?int
    {
        $id = $sessao->get(self::CHAVE_CONTA);

        return is_int($id) ? $id : null;
    }

    public static function esquecer(SessionInterface $sessao): void
    {
        $sessao->remove(self::CHAVE_SESSAO);
    }
}
