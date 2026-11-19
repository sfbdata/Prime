<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Compartilhar pasta recusado (spec acesso-restrito-e-compartilhar §4.4, desenho L5). A mensagem vai para a janela; o
 * controller responde 422 (dado do formulário) ou 403 (quem pede não pode).
 */
final class CompartilhamentoRecusadoException extends \RuntimeException
{
    public const ESCOLHA_O_USUARIO = 'Escolha o usuário da equipe.';
    public const JA_COMPARTILHADA = 'A pasta já está compartilhada com esse usuário.';
    public const DESTINO_RESPONSAVEL = 'Esse usuário já é o responsável pela pasta.';
    public const VALIDADE_PASSADA = 'A validade não pode ser anterior a hoje.';
    public const SEM_PERMISSAO = 'Você não pode compartilhar esta pasta.';
    public const NAO_ENCERRA = 'Você não pode encerrar este acesso.';

    public function __construct(string $mensagem, private readonly bool $daPessoa = false)
    {
        parent::__construct($mensagem);
    }

    /** `true`: quem pede não pode (403); `false`: o dado da janela está errado (422). */
    public function ehDaPessoa(): bool
    {
        return $this->daPessoa;
    }
}
