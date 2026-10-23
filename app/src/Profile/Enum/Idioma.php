<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Idioma do sistema escolhido pela pessoa no Meu Perfil (desenho 07, "Preferências"; `bj-idioma.js`; spec
 * docs/specs/idioma-do-sistema.md). O valor é a etiqueta BCP 47 (`<html lang>`); o locale do Symfony troca o hífen
 * por sublinhado. O seletor oferece só Português (Brasil), Inglês e Espanhol (`oferecidos()`, decisão do dono,
 * 23/10/2026); só os `ativo()` podem ser escolhidos, e por enquanto é só o português: inglês e espanhol aparecem
 * "em breve" até ter catálogo de tradução. Os outros casos saem do seletor mas ficam no enum: quem já gravou um
 * deles no perfil (coluna `user_profiles.idioma`) continua carregando sem erro e vale como português
 * ({@see self::emUso()}). Liberar um idioma é decisão do dono, junto com o catálogo de tradução dele.
 */
enum Idioma: string
{
    case PortuguesBrasil = 'pt-BR';
    case Ingles          = 'en';
    case Espanhol        = 'es';
    case Frances         = 'fr';
    case Italiano        = 'it';
    case Alemao          = 'de';
    case Russo           = 'ru';
    case Chines          = 'zh';
    case Japones         = 'ja';
    case Arabe           = 'ar';

    public const PADRAO = self::PortuguesBrasil;

    /** O nome no próprio idioma, como no seletor do desenho. */
    public function nome(): string
    {
        return match ($this) {
            self::PortuguesBrasil => 'Português (Brasil)',
            self::Ingles          => 'English',
            self::Espanhol        => 'Español',
            self::Frances         => 'Français',
            self::Italiano        => 'Italiano',
            self::Alemao          => 'Deutsch',
            self::Russo           => 'Русский',
            self::Chines          => '中文',
            self::Japones         => '日本語',
            self::Arabe           => 'العربية',
        };
    }

    public function sigla(): string
    {
        return strtoupper(substr($this->value, 0, 2));
    }

    /** Locale do Symfony e do ICU (`pt_BR`). */
    public function locale(): string
    {
        return str_replace('-', '_', $this->value);
    }

    /** Sentido do texto: o árabe é da direita para a esquerda. */
    public function direcao(): string
    {
        return $this === self::Arabe ? 'rtl' : 'ltr';
    }

    /** Pode ser escolhido. Por enquanto só o português: inglês e espanhol esperam o catálogo de tradução. */
    public function ativo(): bool
    {
        return $this === self::PortuguesBrasil;
    }

    /**
     * Os idiomas do seletor (menu do usuário e Meu Perfil), na ordem do desenho: o português e os dois planejados
     * (inglês e espanhol, "em breve" enquanto não estão `ativo()`).
     *
     * @return list<self>
     */
    public static function oferecidos(): array
    {
        return [self::PortuguesBrasil, self::Ingles, self::Espanhol];
    }

    /** O idioma gravado, ou o padrão quando é desconhecido ou ainda não está liberado. */
    public static function emUso(?string $valor): self
    {
        $idioma = $valor !== null ? self::tryFrom($valor) : null;

        return $idioma !== null && $idioma->ativo() ? $idioma : self::PADRAO;
    }
}
