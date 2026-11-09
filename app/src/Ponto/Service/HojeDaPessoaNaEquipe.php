<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\SituacaoDeHojeNaEquipe;

/**
 * O dia de hoje de uma pessoa na aba "Equipe" (spec `docs/specs/ponto-desenho-0811.md`, E5, P54; desenho 16 L779):
 * a situação, a hora da entrada ("HH:MM") e o último registro ("HH:MM · Tipo"), sobre as batidas que a folha
 * escolhe para o dia. O último registro é o do tipo mais adiantado batido (saída, retorno, repouso, entrada), como no
 * desenho. Sem a batida, `null`: a tela escreve "Sem registro" (spec §8, linha 8).
 */
final readonly class HojeDaPessoaNaEquipe
{
    private const NOMES_DOS_TIPOS = [
        RegistroPonto::TIPO_ENTRADA => 'Entrada',
        RegistroPonto::TIPO_REPOUSO => 'Repouso',
        RegistroPonto::TIPO_RETORNO => 'Retorno',
        RegistroPonto::TIPO_SAIDA   => 'Saída',
    ];

    private function __construct(
        public SituacaoDeHojeNaEquipe $situacao,
        public ?string $entrada,
        public ?string $ultimoRegistro,
    ) {
    }

    public static function daEscolha(BatidasEscolhidas $escolha): self
    {
        return new self(
            SituacaoDeHojeNaEquipe::daEscolha($escolha),
            $escolha->entrada?->getDataHora()->format('H:i'),
            self::ultimoRegistro($escolha),
        );
    }

    private static function ultimoRegistro(BatidasEscolhidas $escolha): ?string
    {
        foreach (array_reverse(RegistroPonto::TIPOS_VALIDOS) as $tipo) {
            $batida = $escolha->doTipo($tipo);
            if ($batida !== null) {
                return $batida->getDataHora()->format('H:i') . ' · ' . self::NOMES_DOS_TIPOS[$tipo];
            }
        }

        return null;
    }
}
