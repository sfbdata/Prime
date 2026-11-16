<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma unidade apta que ainda não votou, para o "Lembrar quem não votou". Só identificação e contato: nada sobre voto
 * ou opção (a lista nasce de quem participou, nunca do que escolheu). O telefone vem só com dígitos.
 */
final readonly class UnidadeSemVoto
{
    public function __construct(
        public string $identificacao,
        public string $bloco,
        public ?string $responsavel,
        public ?string $telefone,
    ) {
    }

    /** "Sem nome na lista" é do template; aqui é o nome cru ou null. */
    public function primeiroNome(): ?string
    {
        $nome = trim((string) $this->responsavel);
        if ('' === $nome) {
            return null;
        }

        return explode(' ', $nome)[0];
    }

    /** "A-101 · Bloco A", ou só a identificação sem bloco (como o desenho: identificação e grupo). */
    public function rotulo(): string
    {
        return '' === $this->bloco ? $this->identificacao : $this->identificacao . ' · ' . $this->bloco;
    }

    /** wa.me com DDI 55 quando o número tem até 11 dígitos (DDD e número), como o desenho; null sem telefone. */
    public function linkDoWhatsapp(string $texto): ?string
    {
        if (null === $this->telefone || '' === $this->telefone) {
            return null;
        }
        $numero = (\strlen($this->telefone) <= 11 ? '55' : '') . $this->telefone;

        return 'https://wa.me/' . $numero . '?text=' . rawurlencode($texto);
    }
}
