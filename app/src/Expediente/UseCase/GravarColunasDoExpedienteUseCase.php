<?php

declare(strict_types=1);

namespace App\Expediente\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Exception\ColunasDoExpedienteInvalidasException;

/**
 * Guarda as colunas visíveis da Lista do Expediente (menu "Colunas da tabela") do usuário LOGADO, no
 * escritório da sessão. Quem: a própria pessoa, para ela mesma. Por quê: o ajuste acompanha a pessoa em
 * qualquer navegador e não muda nada para os colegas (antes ficava só no `localStorage`).
 *
 * Valor aceito: mapa `{idDaColuna: bool}` só com as colunas que a tabela tem ({@see self::COLUNAS}, os
 * `data-col` do `_lista.html.twig` e o `COLUNAS` do `expediente-lista.js`). Qualquer outra coisa é
 * recusada com {@see ColunasDoExpedienteInvalidasException} e nada é gravado. `null` apaga o ajuste
 * ("Restaurar padrão"): a tabela volta ao padrão, com todas as colunas.
 *
 * Idempotente: a gravação é `ON CONFLICT` no repositório (uma linha por escritório, usuário e chave).
 */
final class GravarColunasDoExpedienteUseCase
{
    public const CHAVE = 'expediente.colunas';

    /** As colunas da tabela da Lista, na ordem da tela. */
    public const COLUNAS = ['nup', 'cliente', 'reu', 'acao', 'prio', 'resp', 'metas', 'marc', 'sit'];

    private const MAXIMO_DE_ENTRADAS = 40;

    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    public function executar(Tenant $tenant, User $usuario, mixed $colunas): void
    {
        if ($colunas === null) {
            $this->preferencias->apagarDoUsuario($tenant, $usuario, [self::CHAVE]);

            return;
        }

        $this->preferencias->gravar($tenant, $usuario, self::CHAVE, self::exigirMapaValido($colunas));
    }

    /**
     * O mapa aceito, ou a recusa. Público e estático porque a leitura ({@see ObterColunasDoExpedienteUseCase})
     * usa a mesma regra para não devolver à tela um valor que não passaria aqui.
     *
     * @param list<string> $colunasDaTabela as colunas aceitas (as Minhas Demandas somam a "Mod.")
     * @return array<string, bool>
     */
    public static function exigirMapaValido(mixed $colunas, array $colunasDaTabela = self::COLUNAS): array
    {
        if (!is_array($colunas) || count($colunas) > self::MAXIMO_DE_ENTRADAS) {
            throw new ColunasDoExpedienteInvalidasException();
        }

        foreach ($colunas as $coluna => $visivel) {
            if (!in_array($coluna, $colunasDaTabela, true) || !is_bool($visivel)) {
                throw new ColunasDoExpedienteInvalidasException();
            }
        }

        /** @var array<string, bool> $colunas */
        return $colunas;
    }
}
