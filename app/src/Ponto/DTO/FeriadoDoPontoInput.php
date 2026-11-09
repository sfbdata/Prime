<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * O formulário "Feriados do escritório" da aba Configurações (spec `docs/specs/ponto-desenho-0811.md`, E6, P71 com o
 * desvio da E6 e P72): data (`AAAA-MM-DD` do campo de data), nome, abrangência (vazia = "Todas as sedes"; senão o id de
 * uma lotação do escritório) e "Repete todo ano". Quem valida é o `CadastrarFeriadoDoPontoUseCase`.
 */
final class FeriadoDoPontoInput
{
    public function __construct(
        public readonly string $data,
        public readonly string $nome,
        public readonly string $abrangencia,
        public readonly bool $recorrente,
    ) {
    }

    public static function daRequisicao(Request $request): self
    {
        $campo = static fn (string $nome): string => trim((string) $request->request->get($nome, ''));

        return new self($campo('data'), $campo('nome'), $campo('abrangencia'), $request->request->getBoolean('recorrente'));
    }
}
