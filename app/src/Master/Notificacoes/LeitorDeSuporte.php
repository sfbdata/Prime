<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Auth\Entity\MensagemSuporte;
use App\Auth\Repository\MensagemSuporteRepository;
use App\Entity\Auth\User;
use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;

/**
 * Origem `suporte`: o "Falar com suporte" da tela de entrada (`origem = login`, "Suporte") e qualquer outra porta
 * ("Fale conosco"). A tabela não é multi-tenant. A resposta vai ao e-mail DIGITADO na mensagem, que não é confirmado
 * (D5): por isso o e-mail da resposta não cita a mensagem original.
 */
final class LeitorDeSuporte implements OrigemDaCentral
{
    public const ORIGEM = 'suporte';

    public function __construct(
        private readonly MensagemSuporteRepository $mensagens,
    ) {
    }

    public function origem(): string
    {
        return self::ORIGEM;
    }

    public function todas(): array
    {
        return array_map($this->emMensagem(...), $this->mensagens->recentes(self::LIMITE));
    }

    public function uma(int $idOrigem): ?MensagemDaCentral
    {
        $mensagem = $this->mensagens->find($idOrigem);

        return $mensagem instanceof MensagemSuporte ? $this->emMensagem($mensagem) : null;
    }

    public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta
    {
        $mensagem = $this->mensagens->find($idOrigem);

        return $mensagem instanceof MensagemSuporte ? new DestinoDaResposta($mensagem->getEmail()) : null;
    }

    public function registrarResposta(int $idOrigem, string $texto, User $master): void
    {
        // A mensagem de suporte não guarda resposta: o histórico mora só na Central.
    }

    private function emMensagem(MensagemSuporte $m): MensagemDaCentral
    {
        $doLogin = $m->getOrigem() === 'login';

        return new MensagemDaCentral(
            origem: self::ORIGEM,
            idOrigem: (int) $m->getId(),
            tipo: $doLogin ? 'sup' : 'fale',
            rotuloDoTipo: $doLogin ? 'Suporte' : 'Fale conosco',
            deNome: $m->getNome() !== '' ? $m->getNome() : $m->getEmail(),
            deEmail: $m->getEmail(),
            escritorio: $m->getEmail() . ($doLogin ? ' · tela de login' : ''),
            tenantId: null,
            assunto: ($m->getAssunto() ?? '') !== '' ? (string) $m->getAssunto() : 'Mensagem ao suporte',
            texto: $m->getMensagem(),
            em: $m->getCriadaEm(),
            podeResponder: true,
        );
    }
}
