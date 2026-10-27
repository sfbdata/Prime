<?php

declare(strict_types=1);

namespace App\Recompensa\Service;

/**
 * Regra de suspeita de uma conta indicada (antifraude das recompensas; desenho 05 Perfis 1.2 VS 25.10.26, `suspeita()`;
 * spec perfis-1.2-vs-25-10-recompensas-e-planos, F3). Pura, sem banco: recebe o que o repositório leu e devolve os motivos.
 *
 * Os sinais que o sistema tem: conta no MESMO escritório de quem indicou, mesmo CNPJ de escritório (CNPJ vazio nunca
 * casa) e mesmo domínio de e-mail, exceto de provedor público. CPF e aparelho (também no desenho) o BlueJus não guarda.
 */
final class SuspeitaDeConta
{
    public const ESCRITORIO = 'escritorio';
    public const CNPJ = 'cnpj';
    public const DOMINIO = 'dominio';

    /**
     * Quem usa um desses não prova ligação nenhuma só por dividir o provedor. Os sete primeiros são os do desenho; o resto
     * (live, msn, terra, ig, globo, globomail, proton, protonmail, zoho) é ampliação nossa, a confirmar com o dono.
     */
    private const PROVEDORES_PUBLICOS = [
        'gmail', 'hotmail', 'outlook', 'yahoo', 'icloud', 'bol', 'uol',
        'live', 'msn', 'terra', 'ig', 'globo', 'globomail', 'proton', 'protonmail', 'zoho',
    ];

    /**
     * @param list<int>         $escritoriosDaConta
     * @param list<int>         $escritoriosDoIndicador
     * @param list<string|null> $cnpjsDaConta           de todos os escritórios da conta
     * @param list<string|null> $cnpjsDoIndicador       de todos os escritórios do indicador
     *
     * @return list<string> os motivos, na ordem escritório, CNPJ, domínio; vazio quando não há suspeita
     */
    public static function motivos(array $escritoriosDaConta, array $escritoriosDoIndicador, array $cnpjsDaConta, array $cnpjsDoIndicador, ?string $dominioDaConta, ?string $dominioDoIndicador): array
    {
        $motivos = [];
        if (array_intersect($escritoriosDaConta, $escritoriosDoIndicador) !== []) {
            $motivos[] = self::ESCRITORIO;
        }
        $daConta = self::soDigitos($cnpjsDaConta);
        if ($daConta !== [] && array_intersect($daConta, self::soDigitos($cnpjsDoIndicador)) !== []) {
            $motivos[] = self::CNPJ;
        }
        if (self::mesmoDominioCorporativo($dominioDaConta, $dominioDoIndicador)) {
            $motivos[] = self::DOMINIO;
        }

        return $motivos;
    }

    /**
     * Os motivos de uma linha de {@see \App\Indicacao\Repository\IndicacaoRepository::contasParaRecompensas()}; a aprovação do
     * Master NÃO entra aqui (quem chama decide o que fazer com a conta aprovada).
     *
     * @param array{escritorios_conta: list<int>, escritorios_indicador: list<int>, cnpjs_conta: list<string>, cnpjs_indicador: list<string>, dominio_conta: string, dominio_indicador: string} $conta
     *
     * @return list<string>
     */
    public static function daLinha(array $conta): array
    {
        return self::motivos(
            $conta['escritorios_conta'],
            $conta['escritorios_indicador'],
            $conta['cnpjs_conta'],
            $conta['cnpjs_indicador'],
            $conta['dominio_conta'],
            $conta['dominio_indicador'],
        );
    }

    /**
     * @param list<string|null> $cnpjs
     *
     * @return list<string> só os CNPJs com dígitos, sem pontuação
     */
    private static function soDigitos(array $cnpjs): array
    {
        $limpos = array_map(static fn (?string $c): string => preg_replace('/\D/', '', (string) $c) ?? '', $cnpjs);

        return array_values(array_filter($limpos, static fn (string $c): bool => $c !== ''));
    }

    private static function mesmoDominioCorporativo(?string $a, ?string $b): bool
    {
        $a = mb_strtolower(trim((string) $a));
        $b = mb_strtolower(trim((string) $b));
        if ($a === '' || $a !== $b) {
            return false;
        }

        return !in_array(explode('.', $a)[0], self::PROVEDORES_PUBLICOS, true);
    }
}
