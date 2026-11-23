<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A chave "Escritório" de uma função do Ponto não pode mudar (E7). O `motivo` decide a resposta HTTP; a mensagem é
 * escrita para quem está na tela.
 */
final class FuncaoDoPontoRecusadaException extends RecusaDoPonto
{
    /** Sem a permissão de gerir o ponto no escritório (nem primário, nem Master): 403. */
    public const SEM_PERMISSAO = 'sem_permissao';

    /** Chave fora da lista das funções com a camada Escritório: 422. */
    public const DESCONHECIDA = 'desconhecida';

    /** O Master desligou a função para todos os escritórios (P67): 422. */
    public const TRAVADA_PELO_MASTER = 'travada_pelo_master';

    public const RECUSA_SEM_PERMISSAO = 'Só quem administra o ponto do escritório pode mudar esta configuração.';
    public const RECUSA_DESCONHECIDA = 'Função do ponto desconhecida.';
    public const RECUSA_TRAVADA_PELO_MASTER = 'Desativado pelo Master para todos os escritórios.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_SEM_PERMISSAO => 'ponto_servidor.recusa.funcao.sem_permissao',
        self::RECUSA_DESCONHECIDA => 'ponto_servidor.recusa.funcao.desconhecida',
        self::RECUSA_TRAVADA_PELO_MASTER => 'ponto_servidor.recusa.funcao.travada_pelo_master',
    ];

    private function __construct(public readonly string $motivo, string $recusa)
    {
        parent::__construct($recusa);
    }

    public static function semPermissao(): self
    {
        return new self(self::SEM_PERMISSAO, self::RECUSA_SEM_PERMISSAO);
    }

    public static function desconhecida(): self
    {
        return new self(self::DESCONHECIDA, self::RECUSA_DESCONHECIDA);
    }

    public static function travadaPeloMaster(): self
    {
        return new self(self::TRAVADA_PELO_MASTER, self::RECUSA_TRAVADA_PELO_MASTER);
    }
}
