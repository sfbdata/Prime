<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

use App\Entity\Tenant\Tenant;

/**
 * Descadastro de e-mail POR REMETENTE E POR ASSUNTO: o contrato que todo envio de e-mail do sistema
 * que permite "Não quero mais receber" usa (spec push-avisar-quando-chegar D3). Nasceu com o aviso
 * do Push à equipe e é a peça que a campanha de vagas, os alertas da plataforma e os e-mails
 * comerciais reaproveitam.
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
 * Central, alertas de vagas). Os escopos são ISOLADOS: o descadastro no escritório A não vale para o
 * escritório B nem para a plataforma, e o da plataforma não vale para nenhum escritório.
 *
 * Opt-out total: {@see self::ASSUNTO_TODOS}. Uma linha (escopo, e-mail, `todos`) faz
 * `estaDescadastrado` responder true para QUALQUER assunto daquele mesmo escopo (e só dele).
 *
 * Regras do contrato:
 *   - **assunto** é texto livre que casa com {@see self::PADRAO_DO_ASSUNTO} (`[a-z_]{3,40}`), por
 *     exemplo `push_aviso_equipe`; cada envio escolhe o seu. Fora do padrão: \InvalidArgumentException;
 *   - **origem** segue o mesmo padrão (`link_do_email`, `one_click`, `painel`...);
 *   - **email** é normalizado aqui (minúsculo, sem espaços nas pontas): quem chama não precisa;
 *   - o escopo é sempre explícito (nada depende do TenantFilter nem da sessão: vale no CLI e na rota
 *     pública);
 *   - `descadastrar` é idempotente: repetir não falha nem duplica;
 *   - o link não expira (descadastro tem de funcionar no e-mail antigo) e qualquer alteração no
 *     caminho ou na consulta da URL invalida a assinatura (a rota responde 404).
 */
interface DescadastroDeEmailInterface
{
    /** Padrão do assunto e da origem: minúsculas e sublinhado, de 3 a 40 caracteres. */
    public const PADRAO_DO_ASSUNTO = '/^[a-z_]{3,40}$/';

    /** Opt-out total do remetente (no escopo do link): vale para todo assunto daquele escopo. */
    public const ASSUNTO_TODOS = 'todos';

    /**
     * Se o endereço pediu para não receber este assunto (ou nada) deste remetente.
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     */
    public function estaDescadastrado(?Tenant $tenant, string $email, string $assunto): bool;

    /**
     * Registra o pedido no escopo (idempotente).
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     *
     * @throws \InvalidArgumentException e-mail inválido, ou assunto/origem fora do padrão
     */
    public function descadastrar(?Tenant $tenant, string $email, string $assunto, string $origem): void;

    /**
     * URL ABSOLUTA e assinada da rota pública de descadastro, para pôr no e-mail.
     *
     * @param Tenant|null $tenant o escritório remetente; null = a plataforma
     *
     * @throws \InvalidArgumentException e-mail inválido ou assunto fora do padrão
     */
    public function gerarLink(?Tenant $tenant, string $email, string $assunto): string;
}
