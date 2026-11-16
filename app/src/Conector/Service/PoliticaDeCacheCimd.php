<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * Até quando o documento CIMD buscado vale, pelos cabeçalhos HTTP da resposta (`docs/specs/mcp-remoto-oauth.md`, 3.4:
 * "SHOULD guardar em cache pelos cabeçalhos HTTP").
 *
 * `Cache-Control: max-age` manda, descontado o `Age` (RFC 9111, 4.2.3); sem ele, `Expires` (RFC 9111, 5.3). O resultado fica entre o piso de 5 minutos e o
 * teto de 24 horas: o piso segura o app que muda o `client_id` para forçar buscas (junto do limitador
 * `conector_cimd`), e o teto faz um documento trocado pelo app chegar aqui em no máximo um dia. `no-store`, `no-cache`,
 * `Expires` inválido ou cabeçalho ausente dão o piso: o cliente precisa ficar gravado (o consentimento aponta para ele).
 */
final class PoliticaDeCacheCimd
{
    public const PISO_SEGUNDOS = 300;
    public const TETO_SEGUNDOS = 86400;

    /**
     * @param array<string, list<string>> $cabecalhos os cabeçalhos da resposta, com o nome em minúsculas (como o
     *                                                `ResponseInterface::getHeaders()` devolve)
     */
    public function validoAte(array $cabecalhos, \DateTimeImmutable $agora): \DateTimeImmutable
    {
        $segundos = $this->segundosPeloCacheControl($cabecalhos['cache-control'] ?? [], $this->idade($cabecalhos['age'] ?? []))
            ?? $this->segundosPeloExpires($cabecalhos['expires'] ?? [], $agora)
            ?? self::PISO_SEGUNDOS;

        $segundos = max(self::PISO_SEGUNDOS, min(self::TETO_SEGUNDOS, $segundos));

        return $agora->modify(sprintf('+%d seconds', $segundos));
    }

    /**
     * Os segundos do `max-age` menos a idade da resposta; o piso com `no-store` ou `no-cache`; nulo quando o cabeçalho não
     * decide.
     *
     * @param list<string> $valores
     */
    private function segundosPeloCacheControl(array $valores, int $idade): ?int
    {
        $maxAge = null;
        foreach (explode(',', implode(',', $valores)) as $diretiva) {
            $diretiva = strtolower(trim($diretiva));
            if ($diretiva === 'no-store' || $diretiva === 'no-cache') {
                return self::PISO_SEGUNDOS;
            }
            if (preg_match('/^max-age\s*=\s*"?([0-9]{1,10})"?\z/', $diretiva, $partes) === 1) {
                $maxAge = (int) $partes[1];
            }
        }

        return $maxAge === null ? null : $maxAge - $idade;
    }

    /**
     * O cabeçalho `Age` em segundos (RFC 9111, 4.2.3: a resposta já passou esse tempo num cache no caminho e o `max-age`
     * conta a partir da origem). Inválido ou negativo vale zero.
     *
     * @param list<string> $valores
     */
    private function idade(array $valores): int
    {
        $valor = trim($valores[0] ?? '');

        return preg_match('/^[0-9]{1,10}\z/', $valor) === 1 ? (int) $valor : 0;
    }

    /** @param list<string> $valores */
    private function segundosPeloExpires(array $valores, \DateTimeImmutable $agora): ?int
    {
        if ($valores === []) {
            return null;
        }

        $expira = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', trim($valores[0]), new \DateTimeZone('UTC'));
        if ($expira === false) {
            return self::PISO_SEGUNDOS;
        }

        return $expira->getTimestamp() - $agora->getTimestamp();
    }
}
