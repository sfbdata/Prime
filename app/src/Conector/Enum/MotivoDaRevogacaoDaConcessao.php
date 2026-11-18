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
 * - {@see CodigoReutilizado}: o código de autorização foi reapresentado depois de usado (RFC 6749 4.1.2: o servidor
 *   deve revogar o que foi emitido a partir dele). Aprovado pelo Coordenador em 16/11/2026; a coluna `motivo` é
 *   VARCHAR(40) sem CHECK, então não pede migration.
 * - {@see Substituida}: a pessoa autorizou de novo o mesmo app no mesmo escritório; a concessão nova (com os escopos
 *   escolhidos agora) substitui as anteriores do consentimento, que caem na mesma transação (decisão do Coordenador em
 *   18/11/2026, achada no smoke: reconectar só com leitura deixava viva a cadeia antiga, com gravação).
 */
enum MotivoDaRevogacaoDaConcessao: string
{
    case RenovacaoReutilizada = 'renovacao_reutilizada';
    case FalhaNaRenovacao = 'falha_na_renovacao';
    case RevogadaPelaPessoa = 'revogada_pela_pessoa';
    case ContaOuVinculoEncerrado = 'conta_ou_vinculo_encerrado';
    case CodigoReutilizado = 'codigo_reutilizado';
    case Substituida = 'substituida';
}
