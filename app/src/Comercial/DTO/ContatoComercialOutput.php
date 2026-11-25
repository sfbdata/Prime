<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Uma linha da Base de contatos: e-mail, nome, origem (`importacao` ou `manual`, o JS traduz), situação (`ativo` ou
 * `descadastrado`, derivada do descadastro da plataforma), último convite e criação.
 */
final readonly class ContatoComercialOutput
{
    public const ATIVO = 'ativo';
    public const DESCADASTRADO = 'descadastrado';

    public function __construct(
        public int $id,
        public string $email,
        public ?string $nome,
        public string $origem,
        public string $situacao,
        public ?\DateTimeImmutable $ultimoConviteEm,
        public \DateTimeImmutable $criadoEm,
    ) {
    }

    /** @param array<string, mixed> $linha uma linha de `ContatoComercialRepository::pagina()` */
    public static function deLinha(array $linha, bool $descadastrado): self
    {
        return new self(
            (int) $linha['id'],
            (string) $linha['email'],
            \is_string($linha['nome']) ? $linha['nome'] : null,
            (string) $linha['origem'],
            $descadastrado ? self::DESCADASTRADO : self::ATIVO,
            \is_string($linha['ultimo_convite_em']) ? new \DateTimeImmutable($linha['ultimo_convite_em']) : null,
            new \DateTimeImmutable((string) $linha['criado_em']),
        );
    }

    /** @return array{id: int, email: string, nome: ?string, origem: string, situacao: string, ultimoConvite: ?string, criadoEm: string} */
    public function paraJson(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'nome' => $this->nome,
            'origem' => $this->origem,
            'situacao' => $this->situacao,
            'ultimoConvite' => $this->ultimoConviteEm?->format(\DATE_ATOM),
            'criadoEm' => $this->criadoEm->format(\DATE_ATOM),
        ];
    }
}
