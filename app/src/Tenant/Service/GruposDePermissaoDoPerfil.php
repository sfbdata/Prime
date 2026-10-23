<?php
declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Permission\Permission;

/**
 * Arruma o catálogo de permissões nos 6 cartões do desenho "05 - Perfis 1.0" (GRUPOS, linhas
 * 391-398): Administração do escritório · Módulos · Recursos · Cliente · Recursos · Pasta ·
 * Recursos · Processo · Outros recursos, com os rótulos e a ordem do desenho.
 *
 * O catálogo manda no QUE existe; o desenho manda em COMO aparece. Código que o desenho não
 * conhece não some: cai no cartão do seu grupo (admin → Administração, modules → Módulos,
 * resources → Outros recursos) com a descrição gravada no banco. Sumir seria pior que feio: a
 * permissão deixaria de ser concedível e o formulário voltaria a quebrar com "Field already
 * rendered" (regressão do 0c2a780d, coberta pelo TenantRoleFormRenderControllerTest).
 */
final class GruposDePermissaoDoPerfil
{
    /**
     * Cartão => [ícone, [código => rótulo do desenho]]. A ordem dos itens é a do desenho.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const CARTOES = [
        'Administração do escritório' => ['bi-shield', [
            'admin.access_requests.approve' => 'Aprovar ou negar solicitações de acesso por item',
            'admin.audit.view'              => 'Acessar trilha de auditoria',
            'admin.inteligencia.manage'     => 'Configurar a BlueJus IA do escritório',
            'admin.ponto.manage'            => 'Gestão de ponto (sedes, escalas, aprovações)',
            'admin.roles.manage'            => 'Criar, editar e excluir perfis da conta',
            'admin.servicedesk.manage'      => 'Gestão de chamados Service Desk (TI)',
            'admin.tarefas.manage'          => 'Gestão completa de tarefas (visão gestor)',
            'admin.tenant.settings.manage'  => 'Editar configurações do escritório',
            'admin.users.invite'            => 'Convidar usuários secundários',
            'admin.users.manage'            => 'Gerenciar colaboradores (editar perfil, desativar)',
        ]],
        'Módulos' => ['bi-grid', [
            'modules.agenda.view'       => 'Agenda',
            'resources.agenda.ver_prazos' => 'Ver prazos na agenda',
            'modules.bi.view'           => 'BI (futuro)',
            'modules.clientes.view'     => 'Clientes',
            'modules.cobrancas.view'    => 'Gestão de Cobranças',
            'modules.djen.view'         => 'Push Processual (publicações e intimações)',
            'modules.expediente.view'   => 'Expediente',
            'modules.financeiro.view'   => 'Financeiro',
            'modules.inteligencia.view' => 'Usar a BlueJus IA',
            'modules.kanban.view'       => 'Kanban',
            'modules.pastas.view'       => 'Pastas',
            'modules.ponto.view'        => 'Ponto Eletrônico',
            'modules.processos.view'    => 'Processos',
            'modules.servicedesk.view'  => 'Service Desk (usuário)',
            'modules.tarefas.view'      => 'Tarefas (visão do usuário)',
        ]],
        'Recursos · Cliente' => ['bi-people', [
            'resources.cliente.view'   => 'Visualizar',
            'resources.cliente.edit'   => 'Editar',
            'resources.cliente.delete' => 'Excluir',
        ]],
        'Recursos · Pasta' => ['bi-folder2', [
            'resources.pasta.view'   => 'Visualizar',
            'resources.pasta.edit'   => 'Editar',
            'resources.pasta.delete' => 'Excluir',
        ]],
        'Recursos · Processo' => ['bi-bank', [
            'resources.processo.view'   => 'Visualizar',
            'resources.processo.edit'   => 'Editar',
            'resources.processo.delete' => 'Excluir',
        ]],
        'Outros recursos' => ['bi-grid-3x3-gap', [
            'resources.carteira.gerenciar'               => 'Gerenciar carteiras de cobrança e configurações',
            'resources.cobranca.gerenciar'               => 'Gerenciar casos de cobrança (obrigações, contatos, ações)',
            'resources.cobranca.movimentacao_financeira' => 'Registrar ou alterar movimentações financeiras da cobrança',
        ]],
    ];

    /** Cartão que recebe o código desconhecido, pelo grupo gravado na permissão. */
    private const CARTAO_DO_GRUPO = [
        'admin'     => 'Administração do escritório',
        'modules'   => 'Módulos',
        'resources' => 'Outros recursos',
    ];

    private const CARTAO_FALLBACK = 'Outros recursos';

    /**
     * @param array<string, Permission[]> $catalogoPorGrupo saída de PermissionRepository::findAllGrouped()
     *
     * @return list<array{rotulo: string, icone: string, itens: list<array{codigo: string, rotulo: string}>}>
     */
    public function agrupar(array $catalogoPorGrupo): array
    {
        $existentes = [];
        foreach ($catalogoPorGrupo as $grupo => $permissoes) {
            foreach ($permissoes as $permissao) {
                $existentes[(string) $permissao->getCode()] = [
                    'grupo'     => (string) $grupo,
                    'descricao' => (string) $permissao->getDescription(),
                ];
            }
        }

        $itensPorCartao = [];
        $conhecidos = [];
        foreach (self::CARTOES as $cartao => [, $itens]) {
            $itensPorCartao[$cartao] = [];
            foreach ($itens as $codigo => $rotulo) {
                $conhecidos[$codigo] = true;
                if (!isset($existentes[$codigo])) {
                    continue;
                }
                $itensPorCartao[$cartao][] = ['codigo' => $codigo, 'rotulo' => $rotulo];
            }
        }

        foreach ($existentes as $codigo => $dados) {
            if (isset($conhecidos[$codigo])) {
                continue;
            }
            $cartao = self::CARTAO_DO_GRUPO[$dados['grupo']] ?? self::CARTAO_FALLBACK;
            $itensPorCartao[$cartao][] = [
                'codigo' => $codigo,
                'rotulo' => $dados['descricao'] !== '' ? $dados['descricao'] : $codigo,
            ];
        }

        $cartoes = [];
        foreach (self::CARTOES as $cartao => [$icone]) {
            if ($itensPorCartao[$cartao] === []) {
                continue;
            }
            $cartoes[] = ['rotulo' => $cartao, 'icone' => $icone, 'itens' => $itensPorCartao[$cartao]];
        }

        return $cartoes;
    }

    /**
     * @param list<array{rotulo: string, icone: string, itens: list<array{codigo: string, rotulo: string}>}> $cartoes
     */
    public function total(array $cartoes): int
    {
        return array_sum(array_map(static fn (array $c): int => count($c['itens']), $cartoes));
    }
}
