<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

use App\Entity\Tenant\Tenant;

/**
 * Descadastro de e-mail POR ESCRITÓRIO E POR ASSUNTO: o contrato que todo envio de e-mail do
 * sistema que permite "Não quero mais receber" usa (spec push-avisar-quando-chegar D3). Nasceu com
 * o aviso do Push à equipe e é a peça que a campanha de vagas e os e-mails comerciais reaproveitam.
 *
 * Como usar, em quem envia:
 *   1. antes de enviar: `estaDescadastrado($tenant, $email, $assunto)`; se true, não envia;
 *   2. no corpo do e-mail: o link `gerarLink($tenant, $email, $assunto)`, com o texto
 *      "Não quero mais receber estes avisos" (e, se quiser, o cabeçalho List-Unsubscribe com ele);
 *   3. quem clica cai na rota pública `email_descadastro` (sem login), que confere a assinatura,
 *      chama `descadastrar(..., origem: 'link_do_email')` e mostra a confirmação.
 *
 * Regras do contrato:
 *   - **assunto** é texto livre que casa com {@see self::PADRAO_DO_ASSUNTO} (`[a-z_]{3,40}`), por
 *     exemplo `push_aviso_equipe`; cada envio escolhe o seu. Fora do padrão: \InvalidArgumentException;
 *   - **origem** segue o mesmo padrão (`link_do_email`, `one_click`, `painel`...);
 *   - **email** é normalizado aqui (minúsculo, sem espaços nas pontas): quem chama não precisa;
 *   - o escritório é sempre explícito (nada depende do TenantFilter nem da sessão: vale no CLI e
 *     na rota pública);
 *   - `descadastrar` é idempotente: repetir não falha nem duplica;
 *   - o link não expira (descadastro tem de funcionar no e-mail antigo) e qualquer alteração na URL
 *     invalida a assinatura (a rota responde 404).
 */
interface DescadastroDeEmailInterface
{
    /** Padrão do assunto e da origem: minúsculas e sublinhado, de 3 a 40 caracteres. */
    public const PADRAO_DO_ASSUNTO = '/^[a-z_]{3,40}$/';

    /** Se o endereço pediu para não receber este assunto deste escritório. */
    public function estaDescadastrado(Tenant $tenant, string $email, string $assunto): bool;

    /**
     * Registra o pedido (idempotente).
     *
     * @throws \InvalidArgumentException e-mail inválido, ou assunto/origem fora do padrão
     */
    public function descadastrar(Tenant $tenant, string $email, string $assunto, string $origem): void;

    /**
     * URL ABSOLUTA e assinada da rota pública de descadastro, para pôr no e-mail.
     *
     * @throws \InvalidArgumentException e-mail inválido ou assunto fora do padrão
     */
    public function gerarLink(Tenant $tenant, string $email, string $assunto): string;
}
