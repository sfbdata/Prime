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
use App\Master\Entity\DivulgacaoNaSeccional;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Entity\NotaDaCidadeOab;
use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\RecursoEstado;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Entity\RegistroDeBackup;
use App\Master\Entity\SeccionalOab;
use App\Master\Entity\SubsecaoOab;
use App\Master\Repository\PainelMasterRepository;

/**
 * Traduz uma linha do `audit_log` para o rótulo humano da trilha "Segurança e auditoria" e um
 * detalhe CURTO. O detalhe cita só os NOMES dos campos alterados, nunca os valores: o `changes`
 * pode carregar dado sensível (hash de senha, token de convite, CPF) e não sai cru para a tela.
 * Exceção: limites, planos e faixas da Contribua ({@see RotuloDeAuditoriaDoEspaco}), sem dado sensível.
 */
final class RotuloDeAuditoria
{
    public const DETALHE_DO_MASTER = 'Ação confirmada pelo Master';

    /** Rotas das ações do Master sobre a conta (ContasDoSistemaController: master_conta_suspender, master_conta_reativar). */
    private const PREFIXO_ROTA_DO_MASTER = 'master_conta_';

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
        // Seccionais da OAB (spec master-seccionais-oab): sede editada e divulgação registrada pelo Master.
        'endereco'       => 'endereço',
        'telefone'       => 'telefone',
        'site'           => 'site',
        'nota'           => 'nota',
        'tipo'           => 'tipo',
        'data'           => 'data',
        'visivel'        => 'visibilidade no mapa',
        'marcaCor'       => 'cor da marca',
        'marcaStatus'    => 'status da divulgação',
        'marcaEm'        => 'data da divulgação',
        // Desempenho do Master: incidente resolvido.
        'resolvidoEm'    => 'resolvido em',
        'solucao'        => 'solução',
    ];

    private const ENTIDADE = [
        Tenant::class               => ['Conta criada', 'Conta alterada', 'Conta excluída'],
        User::class                 => ['Usuário criado', 'Usuário alterado', 'Usuário removido'],
        UserTenant::class           => ['Usuário vinculado ao escritório', 'Vínculo alterado', 'Usuário removido do escritório'],
        TenantRole::class           => ['Perfil criado', 'Perfil alterado', 'Perfil excluído'],
        TenantRolePermission::class => ['Permissão concedida ao perfil', 'Permissão do perfil alterada', 'Permissão retirada do perfil'],
        Permission::class           => ['Permissão do sistema criada', 'Permissão do sistema alterada', 'Permissão do sistema excluída'],
        Invitation::class           => ['Convite enviado', 'Convite atualizado', 'Convite removido'],
        SeccionalOab::class         => ['Seccional da OAB cadastrada', 'Sede da seccional alterada', 'Seccional da OAB removida'],
        DivulgacaoNaSeccional::class => ['Divulgação registrada na seccional', 'Divulgação na seccional alterada', 'Divulgação na seccional removida'],
        SubsecaoOab::class          => ['Subseção da OAB adicionada', 'Subseção da OAB alterada', 'Subseção da OAB removida'],
        NotaDaSeccional::class      => ['Nota da seccional gravada', 'Nota da seccional alterada', 'Nota da seccional apagada'],
        NotaDaCidadeOab::class      => ['Nota da cidade gravada', 'Nota da cidade alterada', 'Nota da cidade apagada'],
        IncidenteDeDesempenho::class => ['Incidente aberto', 'Incidente alterado', 'Incidente removido'],
        LancamentoDeCusto::class    => ['Custo lançado', 'Custo alterado', 'Custo removido'],
        RegistroDeBackup::class     => ['Backup ou teste registrado', 'Registro de backup alterado', 'Registro de backup removido'],
    ];

    /**
     * @param array{cotaGb: int, limiteUsuarios: ?int}|null $planoDaConta valores de HOJE do plano da conta da
     *                                                            linha, para o "anterior" da primeira mudança de limite
     * @param string|null                                    $recurso      chave da tela ou função quando a linha é de
     *                                                            RecursoEstado (vem da própria `recurso_estado`: a
     *                                                            alteração não regrava a chave)
     *
     * @return array{acao: string, detalhe: string}
     */
    public static function traduzir(string $acao, string $entidade, ?string $mudancasJson, ?array $planoDaConta = null, ?string $recurso = null): array
    {
        $mudancas = self::decodificar($mudancasJson);

        if ($entidade === RecursoEstado::class && $acao !== 'delete') {
            return self::traduzirRecurso($acao, $mudancas, $recurso);
        }

        if ($acao === 'purge') {
            return ['acao' => 'Conta excluída definitivamente', 'detalhe' => 'Purga após a quarentena de exclusão'];
        }

        // Alerta de consumo do Sistema (AlertaDeConsumoNaTrilha): o limiar da faixa alcançada.
        if ($acao === PainelMasterRepository::ACAO_ALERTA_DE_CONSUMO) {
            return ['acao' => 'Alerta de consumo', 'detalhe' => sprintf('Passou de %d%% do armazenamento', (int) ($mudancas['limiar'] ?? 0))];
        }

        // Limites, planos e faixas (Contribua): detalhe com os valores, ver RotuloDeAuditoriaDoEspaco.
        $doEspaco = RotuloDeAuditoriaDoEspaco::traduzir($acao, $entidade, $mudancas, $planoDaConta);
        if ($doEspaco !== null) {
            return $doEspaco;
        }

        $rotulos = self::ENTIDADE[$entidade] ?? ['Registro criado', 'Registro alterado', 'Registro excluído'];
        $campos  = self::camposAlterados($mudancas);

        if ($acao === 'create') {
            return ['acao' => $rotulos[0], 'detalhe' => 'Registro novo'];
        }

        if ($acao === 'delete') {
            return ['acao' => $rotulos[2], 'detalhe' => 'Registro apagado'];
        }

        $detalhe = self::confirmadaPeloMaster($entidade, $mudancas) ? self::DETALHE_DO_MASTER : self::detalheDeCampos($campos);

        return ['acao' => self::rotuloDeAlteracao($entidade, $mudancas, $rotulos[1]), 'detalhe' => $detalhe];
    }

    /**
     * Situação da conta trocada pelo menu da conta no painel do Master (spec perfis-1.2-contas C6):
     * a rota gravada no contexto da linha é uma das ações de conta do Master (`master_conta_*`), que
     * só rodam depois da confirmação no modal. A rota é gravada pelo AuditLogSubscriber em toda linha
     * e não depende de quem é o ator, então o detalhe não aparece em mudança feita por outro caminho.
     *
     * @param array<string, mixed> $mudancas
     */
    private static function confirmadaPeloMaster(string $entidade, array $mudancas): bool
    {
        $rota = $mudancas['context']['route'] ?? null;
        $diff = self::diff($mudancas);

        return $entidade === Tenant::class
            && is_string($rota) && str_starts_with($rota, self::PREFIXO_ROTA_DO_MASTER)
            && (isset($diff['isActive']) || isset($diff['excluidoEm']));
    }

    /**
     * @param array<string, mixed> $mudancas
     */
    private static function rotuloDeAlteracao(string $entidade, array $mudancas, string $padrao): string
    {
        $diff = self::diff($mudancas);

        // A exclusão grava excluidoEm E isActive=false: excluidoEm vem primeiro, senão ela seria lida
        // como suspensão (spec perfis-1.2-contas C6).
        if ($entidade === Tenant::class && isset($diff['excluidoEm']) && is_array($diff['excluidoEm'])) {
            return ($diff['excluidoEm']['to'] ?? null) !== null ? 'Conta excluída' : 'Exclusão de conta cancelada';
        }

        if ($entidade === Tenant::class && isset($diff['isActive']) && is_array($diff['isActive'])) {
            return ($diff['isActive']['to'] ?? null) === false ? 'Conta suspensa' : 'Conta reativada';
        }

        if ($entidade === IncidenteDeDesempenho::class && isset($diff['resolvidoEm'])) {
            return 'Incidente resolvido';
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

        return ['acao' => 'Desativou ' . $titulo, 'detalhe' => self::detalheDeDesligamento(self::comMotivoDeQuemEstavaAtivo($acao, $mudancas, $depois))];
    }

    /**
     * Saindo de ativo, o motivo de antes era sempre nulo (religar limpa motivo e data). Se ele não
     * aparece entre os campos alterados, continuou nulo: é "Sem motivo informado", não uma lacuna.
     *
     * @param array<string, mixed> $mudancas
     * @param array<string, mixed> $depois
     *
     * @return array<string, mixed>
     */
    private static function comMotivoDeQuemEstavaAtivo(string $acao, array $mudancas, array $depois): array
    {
        $saiuDeAtivo = (self::diff($mudancas)['ativo']['from'] ?? null) === true;
        if ($acao === 'update' && $saiuDeAtivo && !array_key_exists('motivo', $depois)) {
            $depois['motivo'] = null;
        }

        return $depois;
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
        // Carimbos técnicos (quando e por quem): não são o que mudou. `atualizadoEm`/`atualizadoPor` são da SeccionalOab;
        // `atualizadaEm`/`atualizadaPor`, da NotaDaSeccional e da NotaDaCidadeOab.
        unset($diff['updatedAt'], $diff['lastLoginAt'], $diff['atualizadoEm'], $diff['atualizadoPor'], $diff['atualizadaEm'], $diff['atualizadaPor']);

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
