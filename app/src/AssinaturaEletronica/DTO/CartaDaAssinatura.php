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
 *
 * Ao signatário, o Reply-To é um usuário do escritório: no convite e no lembrete, quem clicou; na vez e no código, quem
 * enviou o documento (o `usuario` do evento `documento_enviado`). Sem ele, sem Reply-To. O link nulo (o token
 * recalculado não bate com o guardado, segredo trocado depois do envio) faz o e-mail não sair.
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
        public ?EvidenciaDaRequisicao $evidencia = null,
    ) {
    }

    public static function convite(Signatario $signatario, #[\SensitiveParameter] ?string $link, ?User $responderA): self
    {
        return new self(TipoDeCarta::Convite, $signatario->getDocumento(), $signatario, $link, null, $responderA);
    }

    /** Com "em ordem": o aviso ao próximo da fila de que chegou a vez dele. */
    public static function vez(Signatario $signatario, #[\SensitiveParameter] ?string $link, ?User $quemEnviou): self
    {
        return new self(TipoDeCarta::Vez, $signatario->getDocumento(), $signatario, $link, null, $quemEnviou);
    }

    public static function lembrete(Signatario $signatario, #[\SensitiveParameter] ?string $link, ?User $responderA): self
    {
        return new self(TipoDeCarta::Lembrete, $signatario->getDocumento(), $signatario, $link, null, $responderA);
    }

    /** O código pedido na tela pública: a evidência da requisição vai para o evento da entrega. */
    public static function codigo(Signatario $signatario, #[\SensitiveParameter] string $codigo, ?User $quemEnviou, EvidenciaDaRequisicao $evidencia): self
    {
        return new self(TipoDeCarta::Codigo, $signatario->getDocumento(), $signatario, null, $codigo, $quemEnviou, $evidencia);
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

    /** O e-mail que leva o link do signatário e ficou sem ele: não sai. */
    public function semLink(): bool
    {
        return $this->tipo->levaLink() && ($this->link === null || $this->link === '');
    }

    /**
     * O evento que registra a entrega, gravado só depois de o e-mail sair (falhou, só `email_nao_enviado`): assim o
     * lembrete que falhou não gasta a regra de 1 por hora e o código que não saiu não conta no teto diário.
     */
    public function eventoDaEntrega(): ?TipoDeEvento
    {
        return match ($this->tipo) {
            TipoDeCarta::Convite, TipoDeCarta::Vez => TipoDeEvento::ConviteEnviado,
            TipoDeCarta::Lembrete => TipoDeEvento::LembreteEnviado,
            TipoDeCarta::Codigo => TipoDeEvento::CodigoEnviado,
            TipoDeCarta::Recusa, TipoDeCarta::Conclusao => null,
        };
    }
}
