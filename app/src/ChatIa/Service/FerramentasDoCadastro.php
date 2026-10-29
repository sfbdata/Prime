<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Enum\TipoDaProposta;
use App\Inteligencia\DTO\FerramentaDeLinguagem;

/**
 * As duas ferramentas (tool use) que o Chat I.A oferece ao modelo: `propor_cliente` e `propor_pasta`. Elas só
 * PROPÕEM: o servidor guarda uma proposta e quem grava é o clique da pessoa. Quais são oferecidas depende da permissão
 * de quem pergunta ({@see GuardaDasPropostas}).
 */
final class FerramentasDoCadastro
{
    public static function cliente(): FerramentaDeLinguagem
    {
        return new FerramentaDeLinguagem(
            TipoDaProposta::FERRAMENTA_CLIENTE,
            'Propõe o cadastro de um cliente (pessoa física ou jurídica) com os dados que a pessoa enviou ou que estão nos arquivos. '
            . 'Não grava nada: a pessoa confere o cartão e confirma. Preencha só o que estiver visível ou dito; não invente nem complete dados.',
            [
                'type' => 'object',
                'properties' => [
                    'tipo' => ['type' => 'string', 'enum' => [CamposDaProposta::PF, CamposDaProposta::PJ], 'description' => 'pf = pessoa física, pj = pessoa jurídica'],
                    'campos' => CamposDaProposta::esquema(),
                ],
                'required' => ['tipo', 'campos'],
                'additionalProperties' => false,
            ],
        );
    }

    public static function pasta(): FerramentaDeLinguagem
    {
        return new FerramentaDeLinguagem(
            TipoDaProposta::FERRAMENTA_PASTA,
            'Propõe a abertura de uma pasta nova, opcionalmente com o cadastro do cliente ligado a ela. Use esta ferramenta (com `cliente`) quando a pessoa pedir cliente e pasta juntos. '
            . 'Não grava nada: a pessoa confere o cartão e confirma. O número da pasta é sempre gerado pelo sistema: não peça nem informe número.',
            [
                'type' => 'object',
                'properties' => [
                    'nome_cliente' => ['type' => 'string', 'description' => 'Nome do cliente como aparecerá na pasta'],
                    'nome_acao' => ['type' => 'string', 'description' => 'Nome da ação (ex.: Ação de cobrança, Divórcio consensual)'],
                    'cliente' => [
                        'type' => 'object',
                        'description' => 'Cadastro do cliente a criar junto da pasta (omita se a pessoa não enviou dados do cliente)',
                        'properties' => [
                            'tipo' => ['type' => 'string', 'enum' => [CamposDaProposta::PF, CamposDaProposta::PJ]],
                            'campos' => CamposDaProposta::esquema(),
                        ],
                        'required' => ['tipo', 'campos'],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['nome_cliente', 'nome_acao'],
                'additionalProperties' => false,
            ],
        );
    }
}
