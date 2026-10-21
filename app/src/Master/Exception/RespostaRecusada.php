<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao responder pela Central de Notificações (spec master-central-de-notificacoes.md, D7 e D6): alerta, texto
 * vazio ou ninguém a quem responder. O controller devolve 422.
 *
 * Idioma (spec idioma-do-sistema, P3 da b5), no padrão das RECUSA_* e CHAVE_DA_RECUSA da HierarquiaDePerfis: a
 * constante é o português (a fonte, e a mensagem da exceção); o CentralDeNotificacoesController traduz pela chave().
 * O CatalogoDasRecusasDoMasterTest garante que o português do catálogo é igual ao da constante.
 */
final class RespostaRecusada extends \DomainException
{
    public const RECUSA_NAO_ENCONTRADA = 'Mensagem não encontrada.';
    public const RECUSA_SEM_TEXTO = 'Escreva a resposta.';
    public const RECUSA_TEXTO_LONGO = 'A resposta passou de 5.000 caracteres.';
    public const RECUSA_NAO_ACEITA = 'Esta mensagem não aceita resposta.';
    public const RECUSA_SEM_DESTINO = 'Não há mais a quem responder esta mensagem.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_NAO_ENCONTRADA => 'master.notificacoes.recusa.nao_encontrada',
        self::RECUSA_SEM_TEXTO => 'master.notificacoes.recusa.sem_texto',
        self::RECUSA_TEXTO_LONGO => 'master.notificacoes.recusa.texto_longo',
        self::RECUSA_NAO_ACEITA => 'master.notificacoes.recusa.nao_aceita',
        self::RECUSA_SEM_DESTINO => 'master.notificacoes.recusa.sem_destino',
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
