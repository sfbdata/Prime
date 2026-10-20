<?php

declare(strict_types=1);

namespace App\Pasta\Service\EnvioAoCliente;

use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * Espelho da `MensagemDeEmail` do Carteiro (frente 5d, `App\Comercial\Envio`, commit 80c4c7de): os
 * MESMOS campos e as mesmas validações, para a troca da implementação provisória pelo Carteiro ser só
 * de adaptador (spec push-avisar-cliente, "Contrato com o Carteiro").
 *
 * Um destinatário por mensagem. `corpoHtml` é só o miolo, já escapado por quem monta; o layout, o rodapé
 * e o link de descadastro são de quem envia.
 */
final class MensagemAoCliente
{
    public const PADRAO_DA_ORIGEM = '/^[a-z0-9_:-]{3,60}$/';

    public readonly string $para;

    /**
     * @param list<string> $cc
     */
    public function __construct(
        string $para,
        public readonly string $assunto,
        public readonly string $corpoHtml,
        public readonly TipoDeEmailAoCliente $tipo,
        public readonly string $origem,
        public readonly ?string $lista = null,
        public readonly ?Tenant $escopo = null,
        public readonly ?string $nome = null,
        public readonly ?string $selo = null,
        public readonly ?string $titulo = null,
        public readonly ?string $replyTo = null,
        public readonly array $cc = [],
        public readonly bool $rastrear = false,
        public readonly ?string $nomeDoRemetente = null,
    ) {
        $this->para = mb_strtolower(trim($para));
        if (filter_var($this->para, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Endereço de e-mail inválido.');
        }
        self::exigirListaCoerente($tipo, $lista);
        if (preg_match(self::PADRAO_DA_ORIGEM, $origem) !== 1) {
            throw new \InvalidArgumentException('Origem do envio inválida.');
        }
        if (trim($assunto) === '' || trim($corpoHtml) === '') {
            throw new \InvalidArgumentException('Assunto e corpo são obrigatórios.');
        }
        foreach ([$replyTo, ...$cc] as $endereco) {
            if ($endereco !== null && filter_var($endereco, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Endereço de resposta ou cópia inválido.');
            }
        }
    }

    private static function exigirListaCoerente(TipoDeEmailAoCliente $tipo, ?string $lista): void
    {
        if ($tipo === TipoDeEmailAoCliente::Transacional && $lista !== null) {
            throw new \InvalidArgumentException('E-mail transacional não tem lista.');
        }
        if ($tipo === TipoDeEmailAoCliente::Lista && ($lista === null || preg_match(DescadastroDeEmailInterface::PADRAO_DO_ASSUNTO, $lista) !== 1 || $lista === DescadastroDeEmailInterface::ASSUNTO_TODOS)) {
            throw new \InvalidArgumentException('E-mail de lista exige uma lista válida.');
        }
    }
}
