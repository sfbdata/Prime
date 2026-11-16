<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Peças comuns dos testes unitários das entidades do conector (sem banco). */
trait CenarioDoConector
{
    /** PKCE S256 de exemplo da RFC 7636 (apêndice B). */
    private const DESAFIO = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private static function hash(string $semente): string
    {
        return hash('sha256', $semente);
    }

    private static function em(string $quando): \DateTimeImmutable
    {
        return new \DateTimeImmutable($quando);
    }

    private static function clienteCimd(string $url = 'https://claude.ai/oauth/claude-code-client-metadata'): ClienteDoConector
    {
        return new ClienteDoConector($url, TipoDeClienteDoConector::Cimd, 'Claude Code', ['http://127.0.0.1/callback'], self::em('2026-11-16 10:00:00'));
    }

    private static function consentimento(?Tenant $tenant = null, ?User $user = null): ConsentimentoDoConector
    {
        return new ConsentimentoDoConector($user ?? new User(), $tenant ?? new Tenant(), self::clienteCimd(), ['mcp:leitura', 'offline_access'], self::em('2026-11-16 10:00:00'));
    }

    private static function concessao(?ConsentimentoDoConector $consentimento = null, string $autorizadoEm = '2026-11-16 10:00:00', string $senha = 'senha'): ConcessaoDoConector
    {
        return new ConcessaoDoConector($consentimento ?? self::consentimento(), ['mcp:leitura', 'offline_access'], 'https://bluejus.com.br/mcp', self::hash($senha), self::em($autorizadoEm));
    }

    private static function renovacao(ConcessaoDoConector $concessao, string $semente = 'r', string $quando = '2026-11-16 10:00:00', ?TokenDoConector $geradoPor = null): TokenDoConector
    {
        return TokenDoConector::deRenovacao(self::hash($semente), $concessao, self::em($quando), $geradoPor);
    }
}
