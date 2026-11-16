<?php

declare(strict_types=1);

namespace App\Conector\Enum;

/**
 * Por que a concessão (a cadeia de tokens de uma autorização) caiu (`docs/specs/mcp-remoto-oauth.md`, 9.1 e 13.2). Lista
 * fechada: o motivo vai para o banco, nunca texto livre.
 *
 * - {@see RenovacaoReutilizada}: renovação já girada voltou fora da janela de tolerância, ou por outro app (sinal de roubo).
 * - {@see FalhaNaRenovacao}: a troca da renovação falhou numa barreira (vínculo, escritório, senha, corte, liberação).
 * - {@see RevogadaPelaPessoa}: a pessoa revogou a conexão (o consentimento).
 * - {@see ContaOuVinculoEncerrado}: a conta ou o vínculo com o escritório foi excluído ou desativado.
 */
enum MotivoDaRevogacaoDaConcessao: string
{
    case RenovacaoReutilizada = 'renovacao_reutilizada';
    case FalhaNaRenovacao = 'falha_na_renovacao';
    case RevogadaPelaPessoa = 'revogada_pela_pessoa';
    case ContaOuVinculoEncerrado = 'conta_ou_vinculo_encerrado';
}
