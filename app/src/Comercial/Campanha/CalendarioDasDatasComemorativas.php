<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

/**
 * As 17 datas comemorativas da Central de envios (spec `comercial-e-emails.md` §13, F4; desenho 05 VS 21.10.26,
 * `static datas(ano)`, dc L614-618), na ordem do dc, com o nome, o ícone, o título e o texto literais do e-mail.
 *
 * As fixas caem sempre no mesmo dia; as móveis seguem a Páscoa (Carnaval = Páscoa menos 47 dias) ou o 2º domingo do
 * mês (Mães em maio, Pais em agosto). A Páscoa é a mesma conta de Meeus/Jones/Butcher do
 * {@see \App\Agenda\Service\CalendarioDoSistema::pascoa()}, repetida aqui para o Comercial não depender da Agenda.
 *
 * Datas em hora local (America/Sao_Paulo), à meia-noite. A antecedência (0, 1 ou 3 dias) atravessa o ano: o Ano Novo
 * "1 dia antes" sai em 31/12 do ano anterior, e o ano dele continua sendo o da data.
 *
 * @phpstan-type Data array{nome: string, icone: string, regra: string, titulo: string, texto: string}
 */
final class CalendarioDasDatasComemorativas
{
    public const FUSO = 'America/Sao_Paulo';
    /** A hora do envio do dia (dc L680: "envio dd/mm às 9h"). */
    public const HORA_DO_ENVIO = 9;
    public const ANTECEDENCIAS = [0, 1, 3];

    /**
     * A regra: `mm-dd` (fixa), `pascoa+N`/`pascoa-N` (móvel pela Páscoa) ou `domingo:mm:n` (n-ésimo domingo do mês).
     *
     * @var array<string, Data>
     */
    private const DATAS = [
        'ano' => ['nome' => 'Ano Novo', 'icone' => 'bi-stars', 'regra' => '01-01', 'titulo' => 'Um novo ano começa com o escritório em dia', 'texto' => 'Que este ano traga prazos cumpridos, causas ganhas e muita saúde para você e a sua equipe.'],
        'carnaval' => ['nome' => 'Carnaval', 'icone' => 'bi-music-note-beamed', 'regra' => 'pascoa-47', 'titulo' => 'Aproveite o Carnaval com tranquilidade', 'texto' => 'Deixe os prazos sob o controle do BlueJus e aproveite o feriado com quem você ama.'],
        'mulher' => ['nome' => 'Dia Internacional da Mulher', 'icone' => 'bi-flower1', 'regra' => '03-08', 'titulo' => 'Hoje celebramos as mulheres da advocacia', 'texto' => 'Nossa homenagem a todas as advogadas, estagiárias e profissionais que fazem a Justiça acontecer todos os dias.'],
        'pascoa' => ['nome' => 'Páscoa', 'icone' => 'bi-egg', 'regra' => 'pascoa+0', 'titulo' => 'Feliz Páscoa!', 'texto' => 'Que esta Páscoa renove a esperança e traga paz para você e a sua família.'],
        'tiradentes' => ['nome' => 'Tiradentes', 'icone' => 'bi-flag', 'regra' => '04-21', 'titulo' => 'Feriado de Tiradentes', 'texto' => 'Lembrete: confira os prazos que vencem no dia útil seguinte ao feriado.'],
        'trabalho' => ['nome' => 'Dia do Trabalho', 'icone' => 'bi-briefcase', 'regra' => '05-01', 'titulo' => 'Feliz Dia do Trabalho', 'texto' => 'Obrigado pelo trabalho que você e a sua equipe dedicam a cada cliente.'],
        'maes' => ['nome' => 'Dia das Mães', 'icone' => 'bi-heart', 'regra' => 'domingo:05:2', 'titulo' => 'Feliz Dia das Mães', 'texto' => 'Uma homenagem especial a todas as mães que conciliam família e advocacia com tanta força e carinho.'],
        'namorados' => ['nome' => 'Dia dos Namorados', 'icone' => 'bi-hearts', 'regra' => '06-12', 'titulo' => 'Feliz Dia dos Namorados', 'texto' => 'Hoje é dia de deixar o trabalho em dia e aproveitar a noite. O BlueJus cuida dos lembretes.'],
        'pais' => ['nome' => 'Dia dos Pais', 'icone' => 'bi-person-heart', 'regra' => 'domingo:08:2', 'titulo' => 'Feliz Dia dos Pais', 'texto' => 'Nossa homenagem a todos os pais que dividem o tempo entre a família e a advocacia.'],
        'advogado' => ['nome' => 'Dia do Advogado', 'icone' => 'bi-bank', 'regra' => '08-11', 'titulo' => 'Feliz Dia do Advogado!', 'texto' => 'Hoje é o seu dia. Obrigado por defender direitos com dedicação. O BlueJus tem orgulho de fazer parte do seu escritório.'],
        'independencia' => ['nome' => 'Independência do Brasil', 'icone' => 'bi-flag-fill', 'regra' => '09-07', 'titulo' => 'Feriado da Independência', 'texto' => 'Bom feriado! Confira os prazos que vencem no próximo dia útil.'],
        'cliente' => ['nome' => 'Dia do Cliente', 'icone' => 'bi-people', 'regra' => '09-15', 'titulo' => 'Obrigado por ser cliente BlueJus', 'texto' => 'Hoje é o Dia do Cliente e queremos agradecer a confiança do seu escritório.'],
        'criancas' => ['nome' => 'Dia das Crianças', 'icone' => 'bi-balloon', 'regra' => '10-12', 'titulo' => 'Feliz Dia das Crianças', 'texto' => 'Um dia para lembrar da criança que existe em cada um de nós.'],
        'finados' => ['nome' => 'Finados', 'icone' => 'bi-flower3', 'regra' => '11-02', 'titulo' => 'Feriado de Finados', 'texto' => 'Lembrete: confira os prazos que vencem no dia útil seguinte ao feriado.'],
        'consciencia' => ['nome' => 'Consciência Negra', 'icone' => 'bi-people-fill', 'regra' => '11-20', 'titulo' => 'Dia da Consciência Negra', 'texto' => 'Um dia para refletir sobre igualdade e respeito, valores que o Direito defende.'],
        'natal' => ['nome' => 'Natal', 'icone' => 'bi-gift', 'regra' => '12-25', 'titulo' => 'Feliz Natal!', 'texto' => 'Que o Natal traga paz, alegria e união para você, a sua família e toda a equipe do escritório.'],
        'reveillon' => ['nome' => 'Réveillon', 'icone' => 'bi-stars', 'regra' => '12-31', 'titulo' => 'Boas festas e um feliz Ano Novo', 'texto' => 'Obrigado por estar com o BlueJus este ano. Que o próximo seja de ainda mais conquistas.'],
    ];

    /** @return list<string> as 17 chaves, na ordem do dc */
    public static function chaves(): array
    {
        return array_keys(self::DATAS);
    }

    public static function existe(string $chave): bool
    {
        return isset(self::DATAS[$chave]);
    }

    /** @return Data */
    public static function data(string $chave): array
    {
        return self::DATAS[$chave] ?? throw new \InvalidArgumentException('Data comemorativa desconhecida: ' . $chave);
    }

    /** O dia da data no ano, à meia-noite local. */
    public static function noAno(string $chave, int $ano): \DateTimeImmutable
    {
        $regra = self::data($chave)['regra'];
        $fuso = new \DateTimeZone(self::FUSO);
        if (str_starts_with($regra, 'pascoa')) {
            return self::pascoa($ano)->modify(substr($regra, 6) . ' days');
        }
        if (str_starts_with($regra, 'domingo:')) {
            [, $mes, $n] = explode(':', $regra);
            $primeiro = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, (int) $mes), $fuso);
            $atePrimeiroDomingo = (7 - (int) $primeiro->format('w')) % 7;

            return $primeiro->modify('+' . ($atePrimeiroDomingo + 7 * ((int) $n - 1)) . ' days');
        }

        return new \DateTimeImmutable(sprintf('%04d-%s', $ano, $regra), $fuso);
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano), à meia-noite local. */
    public static function pascoa(int $ano): \DateTimeImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = ($h + $l - 7 * $m + 114) % 31 + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia), new \DateTimeZone(self::FUSO));
    }

    /** A próxima ocorrência a partir de hoje, hoje incluído (dc L626: `x.d >= hoje0`). */
    public static function proxima(string $chave, \DateTimeImmutable $hoje): \DateTimeImmutable
    {
        $dia = self::diaLocal($hoje);
        $data = self::noAno($chave, (int) $dia->format('Y'));

        return $data >= $dia ? $data : self::noAno($chave, (int) $dia->format('Y') + 1);
    }

    /** O dia do envio: a data menos a antecedência (dc L680, `envio.setDate(envio.getDate() - r.ant)`). */
    public static function envio(\DateTimeImmutable $data, int $antecedencia): \DateTimeImmutable
    {
        return $data->modify('-' . $antecedencia . ' days');
    }

    /**
     * A ocorrência da data cujo dia de envio é `$dia`, ou null. Olha o ano do dia e o seguinte: o Ano Novo com 1 dia de
     * antecedência é enviado em 31/12 e pertence ao ano seguinte.
     */
    public static function devidaEm(string $chave, int $antecedencia, \DateTimeImmutable $dia): ?\DateTimeImmutable
    {
        $local = self::diaLocal($dia);
        $ano = (int) $local->format('Y');
        foreach ([$ano, $ano + 1] as $candidato) {
            $data = self::noAno($chave, $candidato);
            if (self::envio($data, $antecedencia)->format('Y-m-d') === $local->format('Y-m-d')) {
                return $data;
            }
        }

        return null;
    }

    /** O instante no fuso local, à meia-noite. */
    public static function diaLocal(\DateTimeImmutable $instante): \DateTimeImmutable
    {
        return $instante->setTimezone(new \DateTimeZone(self::FUSO))->setTime(0, 0);
    }
}
