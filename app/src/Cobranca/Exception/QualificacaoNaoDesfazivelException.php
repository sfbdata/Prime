<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de desfazer uma qualificação de contato que não satisfaz as quatro condições da spec §3.5:
 * não é uma qualificação, é de outra pessoa, passou dos 5 minutos, ou já não é a mais recente do caso.
 *
 * Mensagem ÚNICA para os quatro motivos, pelo mesmo raciocínio da `AnotacaoNaoEditavelException`:
 * detalhar qual condição falhou contaria a quem forjou o POST algo sobre um registro que não é dele.
 * Para o autor legítimo dentro da janela o botão sequer é renderizado — quem chega aqui está com a
 * tela velha aberta (janela vencida, outra qualificação registrada no meio) ou forjando a requisição.
 *
 * A mensagem diz o que fazer no lugar: registrar a qualificação certa por cima. O histórico é
 * append-only, e essa é a saída honesta depois que a janela fecha.
 */
final class QualificacaoNaoDesfazivelException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Esta qualificação não pode mais ser desfeita. Só o autor pode desfazer a última '
            . 'qualificação do caso, e apenas nos primeiros 5 minutos. Registre a qualificação correta '
            . 'no lugar.',
        );
    }
}
