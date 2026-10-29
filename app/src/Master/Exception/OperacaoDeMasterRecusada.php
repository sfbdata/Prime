<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa de regra de negócio ao promover ou remover um Master secundário (spec perfis-1.2-masters §4.2).
 * A mensagem é escrita para quem está na tela.
 *
 * Idioma (spec idioma-do-sistema, P3 da b5), no padrão das RECUSA_* e CHAVE_DA_RECUSA da HierarquiaDePerfis: a
 * constante é o português (a fonte, e a mensagem da exceção); o MastersDoSistemaController põe no flash a tradução
 * pela chave(), com o termo Master por parâmetro. O CatalogoDasRecusasDoMasterTest garante que o português do
 * catálogo é igual ao da constante.
 */
final class OperacaoDeMasterRecusada extends \DomainException
{
    public const RECUSA_SO_PRIMARIO = 'Só o Master primário pode promover ou remover Masters.';
    public const RECUSA_OUTRO_ESCRITORIO = 'Entre no escritório do usuário para promovê-lo a Master.';
    public const RECUSA_SEM_ESCOLHA = 'Escolha um usuário cadastrado.';
    public const RECUSA_CONTA_DESATIVADA = 'A conta deste usuário está desativada.';
    public const RECUSA_JA_MASTER = 'Este usuário já é Master.';
    public const RECUSA_SO_USUARIO_ATIVO = 'Só usuários ativos desta conta podem ser promovidos a Master.';
    public const RECUSA_NAO_E_MASTER = 'Este usuário não é Master.';
    public const RECUSA_EXCLUIR_PRIMARIO = 'O Master primário não pode ser excluído.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_SO_PRIMARIO => 'master.masters.recusa.so_primario',
        self::RECUSA_OUTRO_ESCRITORIO => 'master.masters.recusa.outro_escritorio',
        self::RECUSA_SEM_ESCOLHA => 'master.masters.recusa.sem_escolha',
        self::RECUSA_CONTA_DESATIVADA => 'master.masters.recusa.conta_desativada',
        self::RECUSA_JA_MASTER => 'master.masters.recusa.ja_master',
        self::RECUSA_SO_USUARIO_ATIVO => 'master.masters.recusa.so_usuario_ativo',
        self::RECUSA_NAO_E_MASTER => 'master.masters.recusa.nao_e_master',
        self::RECUSA_EXCLUIR_PRIMARIO => 'master.masters.recusa.excluir_primario',
    ];

    private readonly string $chave;

    /** @param string $recusa uma das constantes RECUSA_* */
    public function __construct(string $recusa)
    {
        $this->chave = self::CHAVE_DA_RECUSA[$recusa] ?? throw new \LogicException(sprintf('Recusa sem chave no catálogo: "%s".', $recusa));
        parent::__construct($recusa);
    }

    public function chave(): string
    {
        return $this->chave;
    }
}
