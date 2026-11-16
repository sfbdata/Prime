<?php

declare(strict_types=1);

namespace App\Conector\Enum;

/**
 * Como o app de I.A. chegou ao BlueJus (`docs/specs/mcp-remoto-oauth.md`, 3.4): pelo documento de metadados na URL do
 * `client_id` (CIMD), pelo registro dinâmico (DCR) ou digitado por um administrador (pré-registrado). O pré-registrado
 * fica sem uso no piloto (decisão 14.d); a coluna já nasce com ele para não precisar de migration depois.
 */
enum TipoDeClienteDoConector: string
{
    case Cimd = 'cimd';
    case Dcr = 'dcr';
    case PreRegistrado = 'pre_registrado';
}
