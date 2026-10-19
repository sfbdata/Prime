<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\EditarAnaliseInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Exception\EdicaoDaAnaliseInvalidaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * "Editar" do cartão da Análise IA (P4h, spec `docs/specs/push-editar-analise-ia.md`). Quem: quem tem
 * EDIT na pasta (guarda do controller). O quê: grava a versão do advogado AO LADO da saída do
 * modelo, que fica intacta; o cartão passa a mostrar o selo "editado".
 *
 * Mesma guarda de IDOR das outras ações: a análise é do escritório E da pasta da URL e não está
 * excluída. Fora de `concluida`, a entidade recusa (`\DomainException`, 409 no controller).
 *
 * @throws AnaliseNaoEncontradaException
 * @throws EdicaoDaAnaliseInvalidaException
 */
final class EditarAnaliseUseCase
{
    public const MAXIMO_RESUMO = 2000;
    public const MAXIMO_PONTO = 500;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(int $analiseId, int $pastaId, EditarAnaliseInput $input, User $user, Tenant $tenant): AnaliseOutput
    {
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId);
        if ($analise === null || $analise->estaExcluida()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $resumo = self::limpar($input->resumo);
        if (mb_strlen($resumo) > self::MAXIMO_RESUMO) {
            throw new EdicaoDaAnaliseInvalidaException('Texto longo demais.');
        }

        $pontos = [];
        foreach ($input->pontos as $i => $texto) {
            $texto = self::limpar($texto);
            if (mb_strlen($texto) > self::MAXIMO_PONTO) {
                throw new EdicaoDaAnaliseInvalidaException('Texto longo demais.');
            }
            $pontos[$i] = $texto;
        }

        if ($analise->editar($resumo, $pontos, $user)) {
            $this->analises->salvar($analise, true);
        }

        return AnaliseOutput::fromEntity($analise);
    }

    /** Uma linha só: quebra de linha e tabulação viram espaço; controle, zero-width e bidi saem. */
    private static function limpar(string $texto): string
    {
        $texto = (string) preg_replace('/[\r\n\t]+/u', ' ', $texto);
        $texto = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $texto);

        return trim((string) preg_replace('/ {2,}/u', ' ', $texto));
    }
}
