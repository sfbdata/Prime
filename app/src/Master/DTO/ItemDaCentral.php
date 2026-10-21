<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Entity\CentralMasterEstado;

/** Uma linha da caixa da Central do Master: a mensagem com o estado DESTE Master e o "Respondida" de todos. */
final class ItemDaCentral
{
    public function __construct(
        public readonly MensagemDaCentral $mensagem,
        public readonly bool $lida,
        public readonly bool $estrela,
        public readonly bool $arquivada,
        public readonly bool $respondida,
    ) {
    }

    public static function de(MensagemDaCentral $mensagem, ?CentralMasterEstado $estado, bool $respondida): self
    {
        return new self($mensagem, $estado?->isLida() ?? false, $estado?->temEstrela() ?? false, $estado?->isArquivada() ?? false, $respondida);
    }

    /** As abas do desenho (nfVals, G): só Arquivadas mostra o que está fora da caixa. */
    public function estaNaAba(string $aba): bool
    {
        return match ($aba) {
            AbaDaCentral::NAO_LIDAS => !$this->arquivada && !$this->lida,
            AbaDaCentral::COM_ESTRELA => !$this->arquivada && $this->estrela,
            AbaDaCentral::RESPONDIDAS => !$this->arquivada && $this->respondida,
            AbaDaCentral::ARQUIVADAS => $this->arquivada,
            default => !$this->arquivada,
        };
    }

    /** @return array<string, mixed> */
    public function emJson(): array
    {
        $m = $this->mensagem;

        return [
            'chave' => $m->chave(), 'origem' => $m->origem, 'id' => $m->idOrigem, 'tipo' => $m->tipo, 'rotulo' => $m->rotuloDoTipo,
            'de' => $m->deNome, 'email' => $m->deEmail, 'escritorio' => $m->escritorio, 'assunto' => $m->assunto,
            'texto' => $m->texto, 'em' => $m->em->format(\DateTimeInterface::ATOM), 'podeResponder' => $m->podeResponder,
            'lida' => $this->lida, 'estrela' => $this->estrela, 'arquivada' => $this->arquivada, 'respondida' => $this->respondida,
        ];
    }
}
