<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\UseCase\GravarColunasDoExpedienteUseCase;

/**
 * Guarda as colunas visíveis da tabela das Minhas Demandas (menu "Colunas da tabela", desenho 15) do usuário
 * LOGADO, no escritório da sessão. A tabela é a mesma da Lista do Expediente, mas o ajuste é outro: chave própria
 * (dc `colKey` = `bj-dem-cols`), para escolher colunas aqui não mudar o Expediente e vice-versa.
 *
 * Mesma regra de valor do Expediente ({@see GravarColunasDoExpedienteUseCase::exigirMapaValido}): mapa
 * `{idDaColuna: bool}` só com as colunas da tabela; `null` apaga o ajuste e a tela volta ao padrão do desenho 15.
 */
final class GravarColunasDasDemandasUseCase
{
    public const CHAVE = 'demandas.colunas';

    /** As do Expediente mais a "Mod." (dias desde a abertura), que a tela antiga das Demandas tinha. */
    public const COLUNAS = [...GravarColunasDoExpedienteUseCase::COLUNAS, 'mod'];

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

        $this->preferencias->gravar($tenant, $usuario, self::CHAVE, GravarColunasDoExpedienteUseCase::exigirMapaValido($colunas, self::COLUNAS));
    }
}
