<?php

declare(strict_types=1);

namespace App\Comercial\Enum;

/**
 * A base legal (LGPD, art. 7º) que o Master declara ao confirmar uma campanha (pedido do Farlei M1, item C1, 25/11/2026;
 * campo aprovado pelo Samuel em 27/11/2026). Obrigatória em toda campanha nova, na tela e pelo conector; gravada em
 * `comercial_campanha.base_legal`, na trilha `camp_conf` e mostrada no detalhe do Relatório.
 *
 * As campanhas anteriores a este campo, os testes e as das datas comemorativas (que o sistema cria sozinho) ficam sem
 * base legal declarada (nulo).
 */
enum BaseLegalDoEnvio: string
{
    /** Art. 7º, I: a pessoa autorizou receber (cadastro com aceite, pedido de novidades). */
    case Consentimento = 'consentimento';
    /** Art. 7º, V: a pessoa tem conta no BlueJus e o e-mail é sobre o serviço contratado. */
    case ExecucaoDeContrato = 'execucao_de_contrato';
    /** Art. 7º, IX: contato profissional com interesse legítimo, com o descadastro em um clique. */
    case LegitimoInteresse = 'legitimo_interesse';

    public const RECUSA = 'Escolha a base legal do envio.';

    public function rotulo(): string
    {
        return match ($this) {
            self::Consentimento => 'Consentimento (LGPD, art. 7º, I)',
            self::ExecucaoDeContrato => 'Execução de contrato (LGPD, art. 7º, V)',
            self::LegitimoInteresse => 'Legítimo interesse (LGPD, art. 7º, IX)',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $b): string => $b->value, self::cases());
    }
}
