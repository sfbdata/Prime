<?php

declare(strict_types=1);

namespace App\Auth\Enum;

/**
 * Resultado da verificação da OAB de um usuário contra o Cadastro Nacional de Advogados.
 * Ausência de OAB é representada por status `null` na entidade (não por um caso aqui).
 *
 * - NaoVerificada: tem OAB, mas ainda não foi confirmada (serviço indisponível / pendente de revisão).
 * - Divergente: o CNA respondeu, mas o registro não existe, ou o nome/situação não confere.
 * - Confirmada: advogado regular com nome batendo — único status que libera criar escritório.
 */
enum StatusOab: string
{
    case NaoVerificada = 'nao_verificada';
    case Divergente    = 'divergente';
    case Confirmada    = 'confirmada';
}
