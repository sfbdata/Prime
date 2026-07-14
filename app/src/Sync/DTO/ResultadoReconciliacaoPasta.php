<?php

declare(strict_types=1);

namespace App\Sync\DTO;

/**
 * Acumulador de contadores + mensagens da reconciliação de UMA pasta ({@see \App\Sync\Service\ReconciliadorDePasta}).
 * Substitui o acoplamento a `SymfonyStyle` do comando antigo: o motor por-pasta não faz IO — só
 * incrementa contadores e enfileira mensagens. O chamador (comando imprime; handler Messenger loga)
 * decide o que fazer com `$mensagens`. `fatal=true` sinaliza EntityManager fechado (aborta a rodada).
 */
final class ResultadoReconciliacaoPasta
{
    public int $criadasNoDrive = 0;
    public int $arquivosEnviados = 0;
    public int $arquivosBaixados = 0;
    public int $secoesArquivos = 0;
    public int $googleNative = 0;
    public int $ignoradosTamanho = 0;
    public int $ignoradosNome = 0;
    public int $ignoradosDuplicados = 0;
    public int $erros = 0;

    /** true quando o EntityManager fechou após erro grave — o chamador deve abortar a rodada. */
    public bool $fatal = false;

    /** @var list<string> mensagens (erros/notas por item), na ordem em que ocorreram */
    public array $mensagens = [];

    public function log(string $mensagem): void
    {
        $this->mensagens[] = $mensagem;
    }
}
