<?php

declare(strict_types=1);

namespace App\Pagamento\Enum;

/** O que a reconsulta ao banco fez com o pagamento. Serve ao comando de conciliação para contar e ao teste para conferir. */
enum ResultadoDaReconsulta: string
{
    /** O banco confirmou e o pagamento liberou o consumidor. */
    case Confirmado = 'confirmado';
    /** O banco confirmou, mas o dinheiro não bate ou chegou fora de hora: o Master decide. */
    case Divergente = 'divergente';
    /** O banco cancelou a cobrança e ela foi cancelada aqui. */
    case Cancelado = 'cancelado';
    /** O banco ainda não recebeu nada. */
    case Aguardando = 'aguardando';
    /** Ambiente de testes (sandbox): registrado e avisado, nunca confirma. */
    case Teste = 'teste';
    /** O banco devolveu outro código para a cobrança: nada é confirmado. */
    case Inconsistente = 'inconsistente';
    /** Nada a fazer: pagamento ou banco desconhecido, integração desligada ou pagamento que não aceita mais reconsulta. */
    case Ignorado = 'ignorado';
}
