<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;

/**
 * Traduz uma linha do `audit_log` para o rótulo humano da trilha "Segurança e auditoria" e um
 * detalhe CURTO. O detalhe cita só os NOMES dos campos alterados, nunca os valores: o `changes`
 * pode carregar dado sensível (hash de senha, token de convite, CPF) e não sai cru para a tela.
 */
final class RotuloDeAuditoria
{
    /** Nome humano de cada campo que costuma aparecer nas entidades administrativas. */
    private const CAMPOS = [
        'name'           => 'nome',
        'fullName'       => 'nome',
        'email'          => 'e-mail',
        'password'       => 'senha',
        'roles'          => 'papel no sistema',
        'isActive'       => 'situação',
        'oabNumero'      => 'número da OAB',
        'oabUf'          => 'UF da OAB',
        'oabStatus'      => 'validação da OAB',
        'oabNomeOficial' => 'nome na OAB',
        'oabVerificadaEm' => 'data da validação da OAB',
        'tenantRole'     => 'perfil',
        'lotacao'        => 'lotação',
        'cargo'          => 'cargo',
        'description'    => 'descrição',
        'cnpj'           => 'CNPJ',
        'cidade'         => 'cidade',
        'estado'         => 'estado',
        'excluidoEm'     => 'exclusão',
        'status'         => 'situação',
        'permission'     => 'permissão',
        'codigoFuncionario' => 'código de funcionário',
        'dataAdmissao'   => 'data de admissão',
    ];

    private const ENTIDADE = [
        Tenant::class               => ['Conta criada', 'Conta alterada', 'Conta excluída'],
        User::class                 => ['Usuário criado', 'Usuário alterado', 'Usuário removido'],
        UserTenant::class           => ['Usuário vinculado ao escritório', 'Vínculo alterado', 'Usuário removido do escritório'],
        TenantRole::class           => ['Perfil criado', 'Perfil alterado', 'Perfil excluído'],
        TenantRolePermission::class => ['Permissão concedida ao perfil', 'Permissão do perfil alterada', 'Permissão retirada do perfil'],
        Permission::class           => ['Permissão do sistema criada', 'Permissão do sistema alterada', 'Permissão do sistema excluída'],
        Invitation::class           => ['Convite enviado', 'Convite atualizado', 'Convite removido'],
    ];

    /**
     * `$recurso` é a chave da tela ou função quando a linha é de RecursoEstado (vem da própria
     * `recurso_estado`: a alteração não regrava a chave).
     *
     * @return array{acao: string, detalhe: string}
     */
    public static function traduzir(string $acao, string $entidade, ?string $mudancasJson, ?string $recurso = null): array
    {
        $mudancas = self::decodificar($mudancasJson);

        if ($entidade === RecursoEstado::class && $acao !== 'delete') {
            return self::traduzirRecurso($acao, $mudancas, $recurso);
        }

        if ($acao === 'purge') {
            return ['acao' => 'Conta excluída definitivamente', 'detalhe' => 'Purga após a quarentena de exclusão'];
        }

        $rotulos = self::ENTIDADE[$entidade] ?? ['Registro criado', 'Registro alterado', 'Registro excluído'];
        $campos  = self::camposAlterados($mudancas);

        if ($acao === 'create') {
            return ['acao' => $rotulos[0], 'detalhe' => 'Registro novo'];
        }

        if ($acao === 'delete') {
            return ['acao' => $rotulos[2], 'detalhe' => 'Registro apagado'];
        }

        return ['acao' => self::rotuloDeAlteracao($entidade, $mudancas, $rotulos[1]), 'detalhe' => self::detalheDeCampos($campos)];
    }

    /**
     * @param array<string, mixed> $mudancas
     */
    private static function rotuloDeAlteracao(string $entidade, array $mudancas, string $padrao): string
    {
        $diff = self::diff($mudancas);

        if ($entidade === Tenant::class && isset($diff['isActive']) && is_array($diff['isActive'])) {
            return ($diff['isActive']['to'] ?? null) === false ? 'Conta suspensa' : 'Conta reativada';
        }

        if ($entidade === Tenant::class && isset($diff['excluidoEm']) && is_array($diff['excluidoEm'])) {
            return ($diff['excluidoEm']['to'] ?? null) !== null ? 'Conta enviada para exclusão' : 'Exclusão de conta cancelada';
        }

        if ($entidade === User::class && isset($diff['roles'])) {
            return self::rotuloDePapel($diff['roles']);
        }

        if ($entidade === User::class && isset($diff['oabStatus'])) {
            return 'Validação da OAB';
        }

        if ($entidade === UserTenant::class && isset($diff['tenantRole'])) {
            return 'Perfil do usuário alterado';
        }

        if ($entidade === UserTenant::class && isset($diff['isActive']) && is_array($diff['isActive'])) {
            return ($diff['isActive']['to'] ?? null) === false ? 'Usuário desativado no escritório' : 'Usuário reativado no escritório';
        }

        return $padrao;
    }

    /**
     * Promoção e remoção de Master secundário (spec perfis-1.2-masters M7): entrou ou saiu o
     * ROLE_SUPER_ADMIN. Qualquer outra troca de papel fica com o rótulo genérico.
     */
    private static function rotuloDePapel(mixed $troca): string
    {
        $antes  = is_array($troca) && is_array($troca['from'] ?? null) ? $troca['from'] : [];
        $depois = is_array($troca) && is_array($troca['to'] ?? null) ? $troca['to'] : [];
        $eraMaster = in_array(RegraDosMasters::PAPEL_MASTER, $antes, true);
        $ehMaster  = in_array(RegraDosMasters::PAPEL_MASTER, $depois, true);

        if (!$eraMaster && $ehMaster) {
            return 'Promovido a Master';
        }

        if ($eraMaster && !$ehMaster) {
            return 'Removido de Master';
        }

        return 'Papel no sistema alterado';
    }

    /**
     * Tela ou função ligada/desligada pelo Master (spec perfis-1.2-telas-e-recursos, R7): "Desativou X" e
     * "Reativou X", com o título do catálogo. O motivo e a data de volta são texto mostrado aos usuários,
     * não dado sensível: entram no detalhe quando a linha os registra.
     *
     * @param array<string, mixed> $mudancas
     *
     * @return array{acao: string, detalhe: string}
     */
    private static function traduzirRecurso(string $acao, array $mudancas, ?string $recurso): array
    {
        $depois = $acao === 'create' ? self::estadoCriado($mudancas) : self::valoresNovos($mudancas);
        $chave = $recurso ?? (is_string($depois['chave'] ?? null) ? $depois['chave'] : '');
        $titulo = CatalogoDeRecursos::titulo($chave) ?? ($chave !== '' ? $chave : 'tela ou função');

        if (($depois['ativo'] ?? null) === true) {
            return ['acao' => 'Reativou ' . $titulo, 'detalhe' => 'Liberado para todos os usuários'];
        }

        return ['acao' => 'Desativou ' . $titulo, 'detalhe' => self::detalheDeDesligamento($depois)];
    }

    /**
     * @param array<string, mixed> $depois
     */
    private static function detalheDeDesligamento(array $depois): string
    {
        $partes = [];
        if (array_key_exists('motivo', $depois)) {
            $partes[] = is_string($depois['motivo']) && $depois['motivo'] !== '' ? 'Motivo: ' . $depois['motivo'] : 'Sem motivo informado';
        }

        if (is_string($depois['ate'] ?? null) && strlen($depois['ate']) >= 10) {
            $partes[] = 'volta em ' . implode('/', array_reverse(explode('-', substr($depois['ate'], 0, 10))));
        }

        return $partes === [] ? 'Desativado para os usuários' : implode(' · ', $partes);
    }

    /**
     * @param array<string, mixed> $mudancas
     *
     * @return array<string, mixed>
     */
    private static function estadoCriado(array $mudancas): array
    {
        $depois = $mudancas['diff']['after'] ?? null;

        return is_array($depois) ? $depois : [];
    }

    /**
     * @param array<string, mixed> $mudancas
     *
     * @return array<string, mixed>
     */
    private static function valoresNovos(array $mudancas): array
    {
        $novos = [];
        foreach (self::diff($mudancas) as $campo => $troca) {
            $novos[(string) $campo] = is_array($troca) ? ($troca['to'] ?? null) : null;
        }

        return $novos;
    }

    /**
     * @param list<string> $campos
     */
    private static function detalheDeCampos(array $campos): string
    {
        if ($campos === []) {
            return 'Alteração sem detalhe registrado';
        }

        $nomes = array_values(array_unique(array_map(
            static fn (string $c): string => self::CAMPOS[$c] ?? $c,
            $campos,
        )));

        return 'Campos alterados: ' . implode(', ', $nomes);
    }

    /**
     * @param array<string, mixed> $mudancas
     *
     * @return list<string>
     */
    private static function camposAlterados(array $mudancas): array
    {
        $diff = self::diff($mudancas);
        unset($diff['updatedAt'], $diff['lastLoginAt']);

        return array_map('strval', array_keys($diff));
    }

    /**
     * @param array<string, mixed> $mudancas
     *
     * @return array<string, mixed>
     */
    private static function diff(array $mudancas): array
    {
        $diff = $mudancas['diff']['changes'] ?? null;

        return is_array($diff) ? $diff : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodificar(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $dados = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
