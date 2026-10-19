<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Catálogo FIXO das telas e funções que o Master liga e desliga para todas as contas
 * (spec docs/specs/perfis-1.2-telas-e-recursos.md, R1, R3, R8 e §4).
 *
 * Ordem, rótulos, ícones, grupos e descrições copiados do `bj-recursos.js` do desenho
 * (docs/design/perfis-1.2/bj-recursos.js): 19 linhas e 18 chaves, porque "Contribua" (tela) e
 * "Contribua (atalho)" dividem a chave `contribua` e mudam juntas.
 *
 * O mapa de rotas (§4) mora aqui também, para haver uma fonte só: o guarda de rota
 * ({@see \App\Master\EventListener\BloqueioDeRecursoDesativado}) e a faixa do Master perguntam
 * a este catálogo qual chave uma rota representa. Chave sem rota (tema, instalar, privacidade,
 * as que ainda não existem) age só pelo menu, via `recurso_visivel()`.
 */
final class CatalogoDeRecursos
{
    public const GRUPO_TELAS = 'Telas';
    public const GRUPO_MENU = 'Menu do usuário';
    public const GRUPO_FUNCOES = 'Funções';

    /**
     * g = grupo, chave, t = título, ic = ícone, d = descrição (só nas Funções), tela = é uma tela
     * inteira (tinha `arq` no protótipo), base = essencial (não desliga).
     *
     * @var list<array{g: string, chave: string, t: string, ic: string, d: ?string, tela: bool, base: bool}>
     */
    public const LINHAS = [
        ['g' => self::GRUPO_TELAS, 'chave' => 'dashboard', 't' => 'Dashboard', 'ic' => 'bi-speedometer2', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_TELAS, 'chave' => 'expedientes', 't' => 'Pasta (Expedientes)', 'ic' => 'bi-folder2-open', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_TELAS, 'chave' => 'lista', 't' => 'Expediente · lista de pastas', 'ic' => 'bi-list-ul', 'd' => null, 'tela' => true, 'base' => true],
        ['g' => self::GRUPO_TELAS, 'chave' => 'metas', 't' => 'Minhas Metas', 'ic' => 'bi-bullseye', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_TELAS, 'chave' => 'trabalho', 't' => 'Trabalho Externo', 'ic' => 'bi-briefcase', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_TELAS, 'chave' => 'peticionar', 't' => 'Peticionar', 'ic' => 'bi-file-earmark-text', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_TELAS, 'chave' => 'meuperfil', 't' => 'Meu Perfil', 'ic' => 'bi-person', 'd' => null, 'tela' => true, 'base' => true],
        ['g' => self::GRUPO_TELAS, 'chave' => 'contribua', 't' => 'Contribua', 'ic' => 'bi-heart', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_TELAS, 'chave' => 'pushpub', 't' => 'Push compartilhado (link público)', 'ic' => 'bi-link-45deg', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_TELAS, 'chave' => 'vagaspub', 't' => 'Vagas públicas (link)', 'ic' => 'bi-megaphone', 'd' => null, 'tela' => true, 'base' => false],
        ['g' => self::GRUPO_MENU, 'chave' => 'instalar', 't' => 'Instalar aplicativo', 'ic' => 'bi-download', 'd' => null, 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_MENU, 'chave' => 'idioma', 't' => 'Idioma', 'ic' => 'bi-translate', 'd' => null, 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_MENU, 'chave' => 'tema', 't' => 'Tema escuro', 'ic' => 'bi-moon-stars', 'd' => null, 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_MENU, 'chave' => 'novidades', 't' => 'Novidades', 'ic' => 'bi-stars', 'd' => null, 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_MENU, 'chave' => 'ajuda', 't' => 'Ajuda e atalhos', 'ic' => 'bi-question-circle', 'd' => null, 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_MENU, 'chave' => 'privacidade', 't' => 'Política de Privacidade', 'ic' => 'bi-shield-lock', 'd' => null, 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_MENU, 'chave' => 'contribua', 't' => 'Contribua (atalho)', 'ic' => 'bi-heart', 'd' => null, 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_FUNCOES, 'chave' => 'chat', 't' => 'Chat I.A', 'ic' => 'bi-chat-dots', 'd' => 'Botão flutuante de conversas e assistente', 'tela' => false, 'base' => false],
        ['g' => self::GRUPO_FUNCOES, 'chave' => 'assistente', 't' => 'Assistente de pastas', 'ic' => 'bi-stars', 'd' => 'Agente que lê e organiza pastas', 'tela' => false, 'base' => false],
    ];

    /**
     * Prefixos do NOME da rota de cada chave (§4). A primeira chave que casar vence.
     *
     * @var array<string, list<string>>
     */
    public const ROTAS = [
        'dashboard'   => ['dashboard_'],
        'expedientes' => ['pasta_show'],
        'metas'       => ['tarefa_minhas'],
        'trabalho'    => ['trabalho_externo_'],
        'peticionar'  => ['pasta_peticionar'],
        'novidades'   => ['novidades_'],
        'assistente'  => ['inteligencia_agente'],
        // Push compartilhado: a página pública /p/{endereco} e a API da pasta que gera, renova e revoga os links.
        'pushpub'     => ['push_compartilhado', 'pasta_push_links_'],
    ];

    /**
     * Rotas EXATAS que ficam abertas mesmo dentro de uma chave desligada: o link público de convite
     * do Trabalho Externo, aberto por quem ainda nem tem conta. Lista exata, e não prefixo: as rotas
     * internas de convite (`trabalho_externo_convite_reenviar`, `_revogar`) são API logada e seguem
     * a chave `trabalho`.
     *
     * @var list<string>
     */
    public const ROTAS_SEMPRE_ABERTAS = [
        'trabalho_externo_convite',
        'trabalho_externo_convite_criar_conta',
        'trabalho_externo_convite_aceitar',
    ];

    /** @return list<string> as 18 chaves, na ordem da primeira aparição. */
    public static function chaves(): array
    {
        return array_values(array_unique(array_column(self::LINHAS, 'chave')));
    }

    public static function existe(string $chave): bool
    {
        return in_array($chave, self::chaves(), true);
    }

    public static function ehEssencial(string $chave): bool
    {
        foreach (self::LINHAS as $linha) {
            if ($linha['chave'] === $chave && $linha['base']) {
                return true;
            }
        }

        return false;
    }

    /** Título da primeira linha da chave ("Contribua", e não "Contribua (atalho)"); null se desconhecida. */
    public static function titulo(string $chave): ?string
    {
        foreach (self::LINHAS as $linha) {
            if ($linha['chave'] === $chave) {
                return $linha['t'];
            }
        }

        return null;
    }

    /** Chave que a rota representa, ou null para rota fora do mapa (que nunca é bloqueada). */
    public static function chaveDaRota(string $rota): ?string
    {
        if ($rota === '' || in_array($rota, self::ROTAS_SEMPRE_ABERTAS, true)) {
            return null;
        }

        foreach (self::ROTAS as $chave => $prefixos) {
            foreach ($prefixos as $prefixo) {
                if (str_starts_with($rota, $prefixo)) {
                    return $chave;
                }
            }
        }

        return null;
    }
}
