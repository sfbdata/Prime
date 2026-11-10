<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

/**
 * O nível de modificação depois da assinatura, como o validador de cadeia responde (protocolo `v1`, campo
 * `nivel_de_modificacao`), do menor para o maior: nenhuma, dados de validação (LTV), preenchimento de formulário,
 * anotações ou outra.
 */
enum ModificacaoDaCadeia: string
{
    case Nenhuma = 'nenhuma';
    case Ltv = 'ltv';
    case Formulario = 'formulario';
    case Anotacoes = 'anotacoes';
    case Outra = 'outra';
}
