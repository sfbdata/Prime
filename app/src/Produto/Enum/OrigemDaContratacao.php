<?php

declare(strict_types=1);

namespace App\Produto\Enum;

/**
 * Quem fez a última mudança numa linha de `contratacao` (spec multiproduto, §4): o cadastro pelo Voto, o dono do
 * escritório no Meu Perfil ou o Master. O que o Master encerrou o dono não religa (trava do PR 8).
 */
enum OrigemDaContratacao: string
{
    case Cadastro = 'cadastro';
    case Dono = 'dono';
    case Master = 'master';
}
