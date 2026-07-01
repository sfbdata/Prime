<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\ResultadoVerificacaoOab;
use App\Auth\Enum\StatusOab;

use function Symfony\Component\String\u;

/**
 * Ponto único de validação de OAB (fecha a dívida #4 — a lógica antes duplicada nos UseCases
 * de cadastro passa a viver aqui):
 *
 *  - validarFormato: OAB é opcional; se informada, valida número (dígitos) e UF (2 letras).
 *  - verificar: consulta o backend (CNA) e decide o status, com matcher de nome lenient e
 *    fail-open (serviço indisponível → NaoVerificada, nunca lança).
 */
final class ValidadorOab
{
    private const SITUACAO_REGULAR = 'regular';

    public function __construct(
        private readonly OabWebServiceClientInterface $client,
    ) {
    }

    public function validarFormato(?string $numero, ?string $uf): void
    {
        $semNumero = $numero === null || $numero === '';
        $semUf     = $uf === null || $uf === '';

        if ($semNumero === true && $semUf === true) {
            return; // OAB opcional/ausente
        }

        if ($numero === null || preg_match('/^\d+$/', $numero) !== 1) {
            throw new \InvalidArgumentException('Número da OAB deve conter apenas dígitos.');
        }

        if ($uf === null || preg_match('/^[A-Z]{2}$/', $uf) !== 1) {
            throw new \InvalidArgumentException('UF da OAB deve ter exatamente 2 letras maiúsculas.');
        }
    }

    public function verificar(string $numero, string $uf, string $nome): ResultadoVerificacaoOab
    {
        try {
            $consulta = $this->client->consultar($numero, $uf, $nome);
        } catch (OabIndisponivelException) {
            return new ResultadoVerificacaoOab(StatusOab::NaoVerificada, null, null);
        }

        if ($consulta->existe !== true) {
            return new ResultadoVerificacaoOab(StatusOab::Divergente, null, null);
        }

        $regular  = $this->normalizar($consulta->situacao ?? '') === self::SITUACAO_REGULAR;
        $nomeBate = $this->nomesBatem($nome, $consulta->nomeOficial ?? '');

        $status = ($regular === true && $nomeBate === true) ? StatusOab::Confirmada : StatusOab::Divergente;

        return new ResultadoVerificacaoOab($status, $consulta->nomeOficial, $consulta->situacao);
    }

    /**
     * Matcher lenient: ignora acento/caixa/pontuação/espaços e considera "bate" se todos os
     * tokens do nome mais curto estão contidos no outro (tolera nome do meio omitido, etc.).
     */
    private function nomesBatem(string $digitado, string $oficial): bool
    {
        $tokensDigitado = $this->tokens($digitado);
        $tokensOficial  = $this->tokens($oficial);

        if ($tokensDigitado === [] || $tokensOficial === []) {
            return false;
        }

        [$menor, $maior] = \count($tokensDigitado) <= \count($tokensOficial)
            ? [$tokensDigitado, $tokensOficial]
            : [$tokensOficial, $tokensDigitado];

        foreach ($menor as $token) {
            if (\in_array($token, $maior, true) === false) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function tokens(string $nome): array
    {
        $normalizado = $this->normalizar($nome);
        $limpo       = preg_replace('/[^a-z0-9]+/', ' ', $normalizado) ?? '';

        return array_values(array_filter(explode(' ', trim($limpo)), static fn (string $t): bool => $t !== ''));
    }

    private function normalizar(string $valor): string
    {
        return u($valor)->ascii()->lower()->trim()->toString();
    }
}
