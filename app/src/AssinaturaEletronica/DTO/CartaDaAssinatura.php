<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\TipoDeCarta;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\Entity\Auth\User;

/**
 * Um e-mail a mandar depois do COMMIT (contrato da etapa 2, seção 6). Fica só em memória: o link (com o token) e o
 * código vão direto para o Mailer síncrono, nunca para fila, log ou trilha.
 */
final readonly class CartaDaAssinatura
{
    private function __construct(
        public TipoDeCarta $tipo,
        public DocumentoParaAssinar $documento,
        public ?Signatario $signatario,
        #[\SensitiveParameter]
        public ?string $link = null,
        #[\SensitiveParameter]
        public ?string $codigo = null,
        public ?User $responderA = null,
    ) {
    }

    public static function convite(Signatario $signatario, #[\SensitiveParameter] string $link, ?User $responderA): self
    {
        return new self(TipoDeCarta::Convite, $signatario->getDocumento(), $signatario, $link, null, $responderA);
    }

    /** Com "em ordem": o aviso ao próximo da fila de que chegou a vez dele. */
    public static function vez(Signatario $signatario, #[\SensitiveParameter] string $link): self
    {
        return new self(TipoDeCarta::Vez, $signatario->getDocumento(), $signatario, $link, null, $signatario->getDocumento()->getCriadoPor());
    }

    public static function lembrete(Signatario $signatario, #[\SensitiveParameter] string $link, ?User $responderA): self
    {
        return new self(TipoDeCarta::Lembrete, $signatario->getDocumento(), $signatario, $link, null, $responderA);
    }

    public static function codigo(Signatario $signatario, #[\SensitiveParameter] string $codigo): self
    {
        return new self(TipoDeCarta::Codigo, $signatario->getDocumento(), $signatario, null, $codigo, $signatario->getDocumento()->getCriadoPor());
    }

    /** Ao remetente: quem recusou e o motivo. */
    public static function recusa(Signatario $quemRecusou): self
    {
        return new self(TipoDeCarta::Recusa, $quemRecusou->getDocumento(), $quemRecusou);
    }

    /** Ao remetente: todas as assinaturas registradas. */
    public static function conclusao(DocumentoParaAssinar $documento): self
    {
        return new self(TipoDeCarta::Conclusao, $documento, null);
    }

    /** Para quem vai: o signatário ou o remetente (quem criou o documento). Nulo = não há a quem mandar. */
    public function destinatario(): ?string
    {
        $email = $this->tipo->aoSignatario() ? $this->signatario?->getEmail() : $this->documento->getCriadoPor()?->getEmail();

        return $email !== null && $email !== '' ? $email : null;
    }

    /** O evento que registra a entrega (o do código e o do lembrete já foram gravados na própria ação). */
    public function eventoDaEntrega(): ?TipoDeEvento
    {
        return \in_array($this->tipo, [TipoDeCarta::Convite, TipoDeCarta::Vez], true) ? TipoDeEvento::ConviteEnviado : null;
    }
}
