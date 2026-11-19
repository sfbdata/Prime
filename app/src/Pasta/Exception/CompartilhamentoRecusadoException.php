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
    // Outra conta (fatia 8, desenho L5); os dois últimos não estão no desenho e são necessários (código que não
    // existe e código de quem já é da equipe).
    public const CODIGO_INVALIDO = 'Código inválido. Use o formato BJ-XXXX-XXXX-XXXX.';
    public const CODIGO_PROPRIO = 'Este é o seu próprio código.';
    public const JA_REPUBLICADA = 'Esta pasta já está republicada para esse usuário.';
    public const CODIGO_NAO_ENCONTRADO = 'Nenhum usuário com este código. Confira com quem vai colaborar.';
    public const CODIGO_DA_EQUIPE = 'Este código é de alguém da equipe do escritório. Use Equipe do escritório.';
    // Caixa "Recebidas" (L10): convite que não é da pessoa, já respondido ou vencido.
    public const CONVITE_INDISPONIVEL = 'Este convite não está mais disponível.';

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
