<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Descadastro de e-mail POR REMETENTE E POR ASSUNTO: o contrato que todo envio de e-mail do sistema
 * que permite "Não quero mais receber" usa (spec push-avisar-quando-chegar D3). Nasceu com o aviso
 * do Push à equipe e é a peça que a campanha de vagas, os alertas da plataforma e os e-mails
 * comerciais (frente 5d) reaproveitam.
 *
 * Uso, em 3 passos, em quem envia:
 *   1. antes de enviar: `estaDescadastrado($tenant, $email, $assunto)`; se true, não envia;
 *   2. no corpo do e-mail: o link `gerarLink($tenant, $email, $assunto)`, com o texto
 *      "Não quero mais receber estes avisos" (e o cabeçalho List-Unsubscribe com ele, mais
 *      `List-Unsubscribe-Post: List-Unsubscribe=One-Click`);
 *   3. quem clica cai na rota pública `email_descadastro` (sem login). O GET só mostra o pedido
 *      (endereço mascarado, botão "Confirmar" e a opção de não receber nada deste remetente); o POST
 *      na mesma URL assinada grava. O one-click do provedor (POST sem a opção) grava só o assunto.
 *
 * Escopos (o REMETENTE): `$tenant` = o escritório que envia; `null` = a PLATAFORMA BlueJus (Master,
 * Central, alertas de vagas). Os escopos são ISOLADOS em tudo (verificar, gravar, reativar, listar):
 * o do escritório A não vale para o escritório B nem para a plataforma, e o da plataforma não vale
 * para nenhum escritório.
 *
 * Opt-out total: {@see self::ASSUNTO_TODOS}. Um `todos` ativo bloqueia QUALQUER assunto do mesmo
 * escopo (e só dele), salvo os assuntos liberados depois dele por `reativar` (exceção).
 *
 * Histórico, nada se apaga: um descadastro está ATIVO até ser reativado. Reativar encerra a linha
 * (quando, por onde, por quem); descadastrar de novo cria outra. Regras de `reativar`:
 *   (a) `reativar(x)` encerra o descadastro ativo de x;
 *   (b) `reativar('todos')` encerra TODOS os descadastros ativos do escopo, de qualquer assunto;
 *   (c) com `todos` ativo, `reativar(x)` libera só x: grava uma exceção de x (linha já reativada) e o
 *       `todos` continua valendo para os demais assuntos. Assim `estaDescadastrado(x)` é: há x ativo;
 *       OU há `todos` ativo sem exceção de x posterior a ele;
 *   (d) escopos isolados, como acima. É idempotente: reativar o que já está liberado não grava nada.
 * O double opt-in (frente 5d) é: o endereço confirma que quer voltar a receber, e quem confirma
 * chama `reativar` com a origem do fluxo (ex.: `double_opt_in`).
 *
 * Regras do contrato:
 *   - **assunto** é texto livre que casa com {@see self::PADRAO_DO_ASSUNTO} (`[a-z_]{3,40}`), por
 *     exemplo `push_aviso_equipe`; cada envio escolhe o seu. Fora do padrão: \InvalidArgumentException;
 *   - **origem** (do descadastro e da reativação) segue o mesmo padrão: `link_do_email`, `one_click`,
 *     `manual_master` (registro manual pelo Master), `double_opt_in`...;
 *   - **motivo** é opcional, até 280 caracteres (vazio vira null; maior: \InvalidArgumentException);
 *   - **email** é normalizado aqui (minúsculo, sem espaços nas pontas): quem chama não precisa;
 *   - o escopo é sempre explícito (nada depende do TenantFilter nem da sessão: vale no CLI e na rota
 *     pública);
 *   - `descadastrar` e `reativar` são idempotentes: repetir não falha nem duplica;
 *   - o link não expira (descadastro tem de funcionar no e-mail antigo) e qualquer alteração no
 *     caminho ou na consulta da URL invalida a assinatura (a rota responde 404).
 */
interface DescadastroDeEmailInterface
{
    /** Padrão do assunto e da origem: minúsculas e sublinhado, de 3 a 40 caracteres. */
    public const PADRAO_DO_ASSUNTO = '/^[a-z_]{3,40}$/';

    /** Opt-out total do remetente (no escopo do link): vale para todo assunto daquele escopo. */
    public const ASSUNTO_TODOS = 'todos';

    /** Origem do registro manual feito pelo Master. */
    public const ORIGEM_MANUAL_MASTER = 'manual_master';

    /** Teto de itens por página em `listar`. */
    public const MAXIMO_POR_PAGINA = 100;

    /**
     * Se o endereço não quer receber este assunto deste remetente (ver a regra (c) acima).
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     */
    public function estaDescadastrado(?Tenant $tenant, string $email, string $assunto): bool;

    /**
     * Registra o pedido no escopo (idempotente enquanto houver um ativo).
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     *
     * @throws \InvalidArgumentException e-mail inválido, assunto/origem fora do padrão ou motivo longo
     */
    public function descadastrar(?Tenant $tenant, string $email, string $assunto, string $origem, ?string $motivo = null): void;

    /**
     * Volta a permitir o envio (regras (a) a (d) acima). Idempotente.
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     * @param User|null   $por    quem reativou, quando há alguém logado
     *
     * @throws \InvalidArgumentException e-mail inválido ou assunto/origem fora do padrão
     */
    public function reativar(?Tenant $tenant, string $email, string $assunto, string $origem, ?User $por = null): void;

    /**
     * Os descadastros ATIVOS do escopo, do mais recente para o mais antigo. `busca` filtra o e-mail
     * (contém, sem diferenciar caixa); `pagina` começa em 1; `porPagina` vai de 1 a 100.
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     */
    public function listar(?Tenant $tenant, ?string $busca, int $pagina, int $porPagina): ListaDeDescadastros;

    /**
     * URL ABSOLUTA e assinada da rota pública de descadastro, para pôr no e-mail.
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     *
     * @throws \InvalidArgumentException e-mail inválido ou assunto fora do padrão
     */
    public function gerarLink(?Tenant $tenant, string $email, string $assunto): string;
}
