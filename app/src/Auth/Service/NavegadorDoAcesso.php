<?php

declare(strict_types=1);

namespace App\Auth\Service;

/**
 * Navegador e sistema lidos do User-Agent, de forma simples: família + versão maior e a família do sistema.
 * Serve à impressão do acesso e ao texto do aviso por e-mail. O que não se reconhece fica nulo.
 */
final class NavegadorDoAcesso
{
    private function __construct(
        public readonly ?string $familia,
        public readonly ?string $versaoMaior,
        public readonly ?string $sistema,
    ) {
    }

    public static function deUserAgent(?string $userAgent): self
    {
        $ua = (string) $userAgent;
        [$familia, $versao] = self::navegador($ua);

        return new self($familia, $versao, self::sistema($ua));
    }

    /** "Chrome 120" ou nulo quando o navegador não é reconhecido. */
    public function rotuloDoNavegador(): ?string
    {
        if ($this->familia === null) {
            return null;
        }

        return $this->versaoMaior === null ? $this->familia : $this->familia . ' ' . $this->versaoMaior;
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function navegador(string $ua): array
    {
        // A ordem importa: Edge e Opera também dizem "Chrome"; o Chrome também diz "Safari".
        $regras = [
            'Edge' => '/\b(?:Edg|EdgA|EdgiOS|Edge)\/(\d+)/',
            'Opera' => '/\bOPR\/(\d+)/',
            'Firefox' => '/\b(?:Firefox|FxiOS)\/(\d+)/',
            'Chrome' => '/\b(?:Chrome|CriOS)\/(\d+)/',
            'Safari' => '/\bVersion\/(\d+)[^ ]* (?:Mobile\/\S+ )?Safari\//',
            'Internet Explorer' => '/(?:MSIE |Trident\/.*rv:)(\d+)/',
        ];
        foreach ($regras as $familia => $regra) {
            if (preg_match($regra, $ua, $m) === 1) {
                return [$familia, $m[1]];
            }
        }

        return [null, null];
    }

    private static function sistema(string $ua): ?string
    {
        // iPad e iPhone também dizem "Mac OS X"; Android também diz "Linux": os mais específicos vêm primeiro.
        $regras = [
            'iOS' => '/iPhone|iPad|iPod/',
            'Android' => '/Android/',
            'Chrome OS' => '/CrOS/',
            'Windows' => '/Windows/',
            'macOS' => '/Macintosh|Mac OS X/',
            'Linux' => '/Linux|X11/',
        ];
        foreach ($regras as $sistema => $regra) {
            if (preg_match($regra, $ua) === 1) {
                return $sistema;
            }
        }

        return null;
    }
}
