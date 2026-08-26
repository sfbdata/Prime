<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * CPF/CNPJ crus ou mascarados → máscara brasileira para exibição.
 *
 * Existe porque o banco NÃO é consistente, e isso foi medido na PROD em
 * 2026-08-26: dos 4 clientes PF, 3 guardam o CPF mascarado e 1 guarda só os 11
 * dígitos; dos 3 PJ, os 3 guardam só os 14 dígitos e nenhum tem máscara — a
 * coluna `cnpj` é varchar(14) e o CNPJ mascarado tem 18 caracteres, então a
 * máscara nem caberia ali. Imprimir o valor cru colocaria "12345678000190" na
 * tela ao lado de um "123.456.789-01" bem formatado.
 *
 * A regra é a CONTAGEM DE DÍGITOS, e é deliberadamente a mesma filosofia do
 * `telefone_br` da Cobrança: o que não bate 11 nem 14 volta EXATAMENTE como
 * veio. Mascarar o que não bate exigiria inventar ou descartar dígito, e um
 * documento errado com máscara bonita é pior que o errado à vista — ele deixa
 * de parecer errado.
 *
 * Isto é APRESENTAÇÃO. Não valida dígito verificador e não normaliza para
 * gravação; quem grava continua responsável pelo que grava.
 */
final class DocumentoBrExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('documento_br', $this->documentoBr(...)),
            new TwigFilter('documento_br_rotulo', $this->documentoBrRotulo(...)),
        ];
    }

    public function documentoBr(?string $documento): string
    {
        $original = (string) $documento;
        $digitos  = preg_replace('/\D/', '', $original) ?? '';

        if (\strlen($digitos) === 11) {
            return substr($digitos, 0, 3) . '.' . substr($digitos, 3, 3) . '.'
                 . substr($digitos, 6, 3) . '-' . substr($digitos, 9, 2);
        }

        if (\strlen($digitos) === 14) {
            return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.'
                 . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
        }

        return $original;
    }

    /**
     * Rótulo pela contagem de dígitos, não pela subclasse da entidade: a tela
     * mostra o que o dado É, e um PJ com 11 dígitos gravados está errado de um
     * jeito que rotular "CNPJ" esconderia.
     */
    public function documentoBrRotulo(?string $documento): string
    {
        $digitos = preg_replace('/\D/', '', (string) $documento) ?? '';

        return match (\strlen($digitos)) {
            11      => 'CPF',
            14      => 'CNPJ',
            default => 'Documento',
        };
    }
}
