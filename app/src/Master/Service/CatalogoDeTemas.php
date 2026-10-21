<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Os 11 temas comemorativos (spec docs/specs/temas-comemorativos.md). Nomes, cores, fitas, ícones e mensagens são
 * os literais do desenho (docs/design/perfis-1.2/vs-21-10-26/bj-tema.js), na mesma ordem: a ordem decide o
 * empate entre dois temas de dia específico no mesmo dia (vale o primeiro).
 */
final class CatalogoDeTemas
{
    /** Fuso das datas: Brasília, qualquer que seja o fuso do servidor. */
    public const FUSO = 'America/Sao_Paulo';

    /** @var array<string, TemaComemorativo>|null */
    private static ?array $temas = null;

    /** @return array<string, TemaComemorativo> por id, na ordem do desenho */
    public static function todos(): array
    {
        if (self::$temas !== null) {
            return self::$temas;
        }

        $lista = [
            new TemaComemorativo('mulher', 'Dia Internacional da Mulher', '#c2457a', '#9e2f61', '#fbe7f0', 'linear-gradient(90deg,#f48fb1,#c2457a,#f48fb1)', '💐', 'Feliz Dia da Mulher! Nossa homenagem a todas as advogadas e profissionais do Direito.', mes: 3, dia: 8, domingo: null),
            new TemaComemorativo('maes', 'Dia das Mães', '#b8487e', '#8f3561', '#fbe9f2', 'linear-gradient(90deg,#f8bbd0,#b8487e,#f8bbd0)', '🌷', 'Feliz Dia das Mães! Um carinho especial a todas as mães da advocacia.', mes: 5, dia: null, domingo: 2),
            new TemaComemorativo('pais', 'Dia dos Pais', '#2c5a8c', '#1f4268', '#e6eef7', 'linear-gradient(90deg,#90caf9,#2c5a8c,#90caf9)', '👔', 'Feliz Dia dos Pais! Nossa homenagem aos pais da advocacia.', mes: 8, dia: null, domingo: 2),
            new TemaComemorativo('advogado', 'Dia do Advogado', '#1d3557', '#12233b', '#eef1f7', 'linear-gradient(90deg,#c9a227,#f1d47b,#c9a227)', '⚖️', 'Feliz Dia do Advogado! Obrigado por defender direitos todos os dias.', mes: 8, dia: 11, domingo: null),
            new TemaComemorativo('independencia', 'Independência do Brasil', '#1e7b45', '#145a32', '#e6f4ec', 'linear-gradient(90deg,#1e7b45,#f2c230,#1e7b45)', 'Brasil', '7 de Setembro: viva o Brasil!', mes: 9, dia: 7, domingo: null),
            new TemaComemorativo('criancas', 'Dia das Crianças', '#6b3fc5', '#4f2d97', '#efe9fb', 'linear-gradient(90deg,#ef5350,#ffa726,#ffee58,#66bb6a,#42a5f5,#ab47bc)', '🎈', 'Feliz Dia das Crianças! Que a criança que existe em você também comemore hoje.', mes: 10, dia: 12, domingo: null, animado: true),
            new TemaComemorativo('outrosa', 'Outubro Rosa', '#d0558f', '#a63e70', '#fcebf3', 'linear-gradient(90deg,#f8bbd0,#d0558f,#f8bbd0)', '🎗️', 'Outubro Rosa: prevenção ao câncer de mama. Cuide-se e faça seus exames.', mes: 10, dia: 1, domingo: null, duracao: 31, fraco: true),
            new TemaComemorativo('novazul', 'Novembro Azul', '#1f6fbf', '#155291', '#e6f0fa', 'linear-gradient(90deg,#90caf9,#1f6fbf,#90caf9)', '🎗️', 'Novembro Azul: prevenção ao câncer de próstata. Cuide da sua saúde.', mes: 11, dia: 1, domingo: null, duracao: 30, fraco: true),
            new TemaComemorativo('justica', 'Dia da Justiça', '#24406e', '#182c4d', '#eaeef6', 'linear-gradient(90deg,#24406e,#c9a227,#24406e)', '🏛️', '8 de Dezembro, Dia da Justiça: nossa homenagem a quem faz a Justiça acontecer.', mes: 12, dia: 8, domingo: null),
            new TemaComemorativo('natal', 'Natal', '#b22234', '#8a1a28', '#fbeaec', 'repeating-linear-gradient(90deg,#b22234 0 22px,#ffffff 22px 30px,#1e7b45 30px 52px,#ffffff 52px 60px)', '🎄', 'Feliz Natal! Que a sua família e o seu escritório tenham um fim de ano de paz.', mes: 12, dia: 18, domingo: null, duracao: 8),
            new TemaComemorativo('reveillon', 'Réveillon', '#8a6d1f', '#5f4a12', '#f8f1de', 'linear-gradient(90deg,#f1d47b,#8a6d1f,#f1d47b)', '🥂', 'Feliz Ano Novo! Que o próximo ano traga muitas vitórias para o seu escritório.', mes: 12, dia: 26, domingo: null, duracao: 7),
        ];

        $porId = [];
        foreach ($lista as $tema) {
            $porId[$tema->id] = $tema;
        }

        return self::$temas = $porId;
    }

    public static function existe(string $id): bool
    {
        return isset(self::todos()[$id]);
    }

    public static function buscar(string $id): ?TemaComemorativo
    {
        return self::todos()[$id] ?? null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::todos());
    }

    /**
     * Hoje no fuso de Brasília, à meia-noite.
     */
    public static function diaEmBrasilia(\DateTimeImmutable $agora): \DateTimeImmutable
    {
        return $agora->setTimezone(new \DateTimeZone(self::FUSO))->setTime(0, 0);
    }
}
