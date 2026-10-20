<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Cliente\Enum\ChaveDoEnvioAutomatico;

/**
 * Uma linha do bloco "Envio automático" do cartão Avisar cliente (desenho `autos`, dc N:5270): o
 * rótulo, o estado e a dica, com o prefixo "Cadastre um e-mail para ativar. " quando o cliente não tem
 * e-mail nenhum (aí o switch fica esmaecido e o clique abre o formulário de cadastro).
 */
final readonly class ItemDoEnvioAutomaticoOutput
{
    public const PREFIXO_SEM_EMAIL = 'Cadastre um e-mail para ativar. ';

    public function __construct(
        public string $chave,
        public string $rotulo,
        public bool $ligado,
        public bool $semEmail,
    ) {
    }

    /** @return list<self> as três linhas, na ordem do desenho */
    public static function lista(ChavesDoEnvioAutomatico $chaves, bool $semEmail): array
    {
        return array_map(
            static fn (ChaveDoEnvioAutomatico $c): self => new self($c->value, $c->rotulo(), $chaves->ligada($c), $semEmail),
            ChaveDoEnvioAutomatico::cases(),
        );
    }

    public function dica(): string
    {
        $chave = ChaveDoEnvioAutomatico::from($this->chave);

        return ($this->semEmail ? self::PREFIXO_SEM_EMAIL : '') . $chave->dica();
    }
}
