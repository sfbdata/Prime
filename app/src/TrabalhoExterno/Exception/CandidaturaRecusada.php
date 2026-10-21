<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Exception;

/**
 * A candidatura (ou o envio de candidatos) foi recusada com título e mensagem para o toast (o
 * controller responde 422 `{ok:false, titulo, mensagem, quase}`). `quase` liga o modal "QUASE" do
 * desenho (l.392 a 400) quando a oferta acabou de ser preenchida e a configuração `motiv` está ligada.
 * `curriculoSalvo` avisa a tela, no "Salvar e candidatar-se", que o currículo já ficou gravado.
 */
final class CandidaturaRecusada extends \DomainException
{
    public function __construct(
        public readonly string $titulo,
        string $mensagem,
        public readonly bool $quase = false,
        public readonly bool $curriculoSalvo = false,
    ) {
        parent::__construct($mensagem);
    }

    /** A mesma recusa, depois de o currículo já ter sido salvo. */
    public function comCurriculoSalvo(): self
    {
        return new self($this->titulo, $this->getMessage(), $this->quase, true);
    }
}
