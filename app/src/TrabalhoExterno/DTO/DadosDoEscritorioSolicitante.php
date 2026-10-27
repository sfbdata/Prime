<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * O que o usuário digitou para guardar um escritório na "Nova solicitação" (nome, e-mail, telefone, UF
 * e município). Texto aparado; campo opcional vazio vira null.
 */
final class DadosDoEscritorioSolicitante
{
    #[Assert\NotBlank(message: 'Informe o nome do escritório para salvar.')]
    #[Assert\Length(max: 120, maxMessage: 'O nome do escritório aceita até 120 caracteres.')]
    public string $nome = '';

    #[Assert\Email(message: 'Informe um e-mail válido.')]
    #[Assert\Length(max: 180, maxMessage: 'O e-mail aceita até 180 caracteres.')]
    public ?string $email = null;

    #[Assert\Length(max: 30, maxMessage: 'O telefone aceita até 30 caracteres.')]
    public ?string $telefone = null;

    #[Assert\Choice(choices: DadosDaSolicitacao::UFS, message: 'Escolha uma UF válida.')]
    public ?string $uf = null;

    #[Assert\Length(max: 80, maxMessage: 'O município aceita até 80 caracteres.')]
    public ?string $cidade = null;

    /** @param array<string, mixed> $dados */
    public static function deArray(array $dados): self
    {
        $texto = static function (string $campo) use ($dados): ?string {
            $valor = $dados[$campo] ?? null;
            $valor = is_scalar($valor) ? trim((string) $valor) : '';

            return $valor === '' ? null : $valor;
        };

        $d = new self();
        $d->nome = $texto('nome') ?? '';
        $d->email = $texto('email');
        $d->telefone = $texto('telefone');
        $uf = $texto('uf');
        $d->uf = $uf === null ? null : mb_strtoupper($uf);
        $d->cidade = $texto('cidade');

        return $d;
    }
}
