<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Campanha\ComposicaoDaCampanha;

/**
 * Um destinatário de campanha: o e-mail normalizado (minúsculo, sem espaços) e o nome, já limpo dos caracteres
 * `;:()<>"'` (spec §8.8, item 12: o `{nome}` entra só escapado no `style` e no `href` do HTML importado). Nome vazio =
 * sem nome (o envio usa "colega", a prévia "Dra. Ana", como a 1a e o dc).
 *
 * `lista` é a lista de consentimento pela qual ele entrou no público (spec §11): `comercial_campanhas` por padrão, ou
 * `vagas_alerta` para quem só autorizou vagas e recebe o modelo `vagas`. Ela decide o link de descadastro, o
 * List-Unsubscribe e a checagem do Carteiro. Não entra no {@see self::paraArray()} (o público guardado não muda).
 */
final class DestinatarioDaCampanha
{
    public const TAMANHO_DO_NOME = 180;
    private const PROIBIDOS_NO_NOME = [';', ':', '(', ')', '<', '>', '"', "'"];

    public readonly string $email;
    public readonly string $nome;

    public function __construct(string $email, ?string $nome = '', public readonly string $lista = ComposicaoDaCampanha::LISTA)
    {
        $this->email = mb_strtolower(trim($email));
        $this->nome = self::limparNome((string) $nome);
    }

    public static function limparNome(string $nome): string
    {
        $limpo = trim((string) preg_replace('/\s+/u', ' ', str_replace(self::PROIBIDOS_NO_NOME, '', $nome)));

        return mb_substr($limpo, 0, self::TAMANHO_DO_NOME);
    }

    /** O endereço passa no filtro do dc (L.534, L.545) e na validação do Carteiro. */
    public function valido(): bool
    {
        return preg_match('/^\S+@\S+\.\S+$/', $this->email) === 1 && filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array{email: string, nome: string} */
    public function paraArray(): array
    {
        return ['email' => $this->email, 'nome' => $this->nome];
    }
}
