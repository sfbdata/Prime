<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A justificativa não pode ser excluída pelo colaborador (P41 do desenho do Ponto). O `motivo`
 * decide a resposta HTTP; a mensagem é escrita para o usuário final e vai direto para a tela.
 */
final class ExclusaoDeJustificativaRecusadaException extends \DomainException
{
    /** Não existe neste escritório (ou sumiu sob a trava): para quem pede, é 404. */
    public const NAO_ENCONTRADA = 'nao_encontrada';

    /** Existe no escritório, mas é de outra pessoa: 403. */
    public const DE_OUTRA_PESSOA = 'de_outra_pessoa';

    /** O gestor já analisou: 422, a exclusão não vale mais. */
    public const JA_ANALISADA = 'ja_analisada';

    private function __construct(public readonly string $motivo, string $mensagem)
    {
        parent::__construct($mensagem);
    }

    public static function naoEncontrada(): self
    {
        return new self(self::NAO_ENCONTRADA, 'Justificativa não encontrada.');
    }

    public static function deOutraPessoa(): self
    {
        return new self(self::DE_OUTRA_PESSOA, 'Só quem enviou a justificativa pode excluí-la.');
    }

    public static function jaAnalisada(): self
    {
        return new self(self::JA_ANALISADA, 'Esta justificativa não está mais em análise e não pode ser excluída.');
    }
}
