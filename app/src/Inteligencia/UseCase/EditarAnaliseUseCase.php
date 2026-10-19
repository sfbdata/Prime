<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\EditarAnaliseInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Exception\EdicaoDaAnaliseConcorrenteException;
use App\Inteligencia\Exception\EdicaoDaAnaliseInvalidaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * "Editar" do cartão da Análise IA (P4h, spec `docs/specs/push-editar-analise-ia.md`). Quem: quem tem
 * EDIT na pasta (guarda do controller). O quê: grava a versão do advogado AO LADO da saída do
 * modelo, que fica intacta; o cartão passa a mostrar o selo "editado".
 *
 * Mesma guarda de IDOR das outras ações: a análise é do escritório E da pasta da URL e não está
 * excluída. Só a análise do Push (`resumo_push`): a dos agentes tem o texto integral e outro
 * cartão, fora do desenho (404). Fora de `concluida`, a entidade recusa (`\DomainException`, 409).
 *
 * Texto que volta igual ao exibido (depois da mesma limpeza) é tratado como não mexido: fica o
 * valor exibido exato, sem limite de tamanho, e não marca "editado" à toa. A `versao` do POST é o
 * `editada_em` que a tela viu: se outra pessoa salvou depois, recusa (os índices dos pontos mudaram).
 *
 * @throws AnaliseNaoEncontradaException
 * @throws EdicaoDaAnaliseInvalidaException
 * @throws EdicaoDaAnaliseConcorrenteException
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
        if ($analise === null || $analise->estaExcluida() || $analise->getTipo() !== TipoDeAnalise::ResumoPush) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        if ($input->versao !== null && $input->versao !== self::versaoDe($analise)) {
            throw new EdicaoDaAnaliseConcorrenteException('Outra pessoa editou esta análise. Recarregue a página e edite de novo.');
        }

        $resumo = self::manterOuLimpar($input->resumo, (string) $analise->getResumoExibido(), self::MAXIMO_RESUMO);

        $exibidos = $analise->getPontosExibidos();
        $pontos = [];
        foreach ($input->pontos as $i => $texto) {
            $pontos[$i] = self::manterOuLimpar($texto, $exibidos[$i]['texto'] ?? null, self::MAXIMO_PONTO);
        }

        if ($analise->editar($resumo, $pontos, $user)) {
            $this->analises->salvar($analise, true);
        }

        return AnaliseOutput::fromEntity($analise);
    }

    /** O `editada_em` que a tela viu ('' sem edição): campo `versao` do formulário. */
    public static function versaoDe(AnaliseDeInteligencia $analise): string
    {
        return $analise->getEditadaEm()?->format('U') ?? '';
    }

    /** @throws EdicaoDaAnaliseInvalidaException */
    private static function manterOuLimpar(string $digitado, ?string $exibido, int $maximo): string
    {
        $limpo = self::limpar($digitado);
        if ($exibido !== null && $limpo === self::limpar($exibido)) {
            return $exibido;
        }
        if (mb_strlen($limpo) > $maximo) {
            throw new EdicaoDaAnaliseInvalidaException('Texto longo demais.');
        }

        return $limpo;
    }

    /**
     * Uma linha só: quebra de linha e tabulação viram espaço; controle, zero-width e bidi saem; e a
     * regra "semTraco" do interpretador (travessão e hífen solto viram vírgula), porque o texto
     * editado vai às mesmas telas e ao link público que a saída do modelo.
     */
    private static function limpar(string $texto): string
    {
        $texto = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $texto);
        $texto = (string) preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', $texto);
        $texto = (string) preg_replace('/\s+-\s+/u', ', ', $texto);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
