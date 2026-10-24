<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Enum\SituacaoDoLinkDeConfirmacao;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use Psr\Clock\ClockInterface;

/**
 * As regras do token do link de confirmação do e-mail, num lugar só: a página do link (GET, que só olha) e a criação
 * da senha (POST) conferem igual (spec `cadastro-senha-no-link.md`).
 *
 * Token desconhecido ou vencido: \DomainException com ERRO_LINK. Conta já confirmada vem ANTES do vencimento: quem
 * clica de novo depois de 7 dias ouve a verdade (está confirmado). Nada aqui grava.
 */
final class LinkDeConfirmacaoDeEmail
{
    public const ERRO_LINK = 'Este link de confirmação é inválido ou expirou.';

    public function __construct(
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** O que a página do link mostra. Não confirma nada. */
    public function situacao(string $token): SituacaoDoLinkDeConfirmacao
    {
        return $this->pendente($token) === null
            ? SituacaoDoLinkDeConfirmacao::JaConfirmado
            : SituacaoDoLinkDeConfirmacao::Pendente;
    }

    /**
     * O link que ainda pode confirmar a conta, ou null se a conta já está confirmada.
     *
     * @throws \DomainException com ERRO_LINK, para token desconhecido ou vencido
     */
    public function pendente(string $token): ?ConfirmacaoEmail
    {
        $confirmacao = $this->confirmacaoEmailRepository->encontrarPorToken($token);

        if ($confirmacao === null) {
            throw new \DomainException(self::ERRO_LINK);
        }

        if ($confirmacao->getUser()->isEmailConfirmado()) {
            return null;
        }

        if ($confirmacao->isExpirado($this->relogio->now())) {
            throw new \DomainException(self::ERRO_LINK);
        }

        return $confirmacao;
    }
}
