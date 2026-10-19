<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * O que um consumidor pede ao {@see CarteiroInterface} (spec `comercial-e-emails.md` §3.1). Imutável e validada no
 * construtor: uma mensagem incoerente nunca chega à fila.
 *
 * Um destinatário por mensagem (o descadastro e o rastreio são por endereço); para vários, uma chamada por pessoa.
 * `corpoHtml` é só o miolo (a versão texto é gerada); o layout, o rodapé legal e o link de descadastro são do Carteiro (B3, não editáveis).
 */
final class MensagemDeEmail
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
        public readonly TipoDeEnvio $tipo,
        public readonly string $origem,
        public readonly ?string $lista = null,
        public readonly ?Tenant $escopo = null,
        public readonly ?string $nome = null,
        public readonly ?string $selo = null,
        public readonly ?string $titulo = null,
        public readonly ?string $replyTo = null,
        public readonly array $cc = [],
        public readonly bool $rastrear = false,
        /** Só o nome exibido ("Escritório X via BlueJus"); o endereço é sempre o remetente da plataforma. */
        public readonly ?string $nomeDoRemetente = null,
    ) {
        $this->para = self::normalizar($para);
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

    public static function normalizar(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function exigirListaCoerente(TipoDeEnvio $tipo, ?string $lista): void
    {
        if ($tipo === TipoDeEnvio::Transacional && $lista !== null) {
            throw new \InvalidArgumentException('E-mail transacional não tem lista.');
        }
        if ($tipo === TipoDeEnvio::Lista && ($lista === null || preg_match(DescadastroDeEmailInterface::PADRAO_DO_ASSUNTO, $lista) !== 1 || $lista === DescadastroDeEmailInterface::ASSUNTO_TODOS)) {
            throw new \InvalidArgumentException('E-mail de lista exige uma lista válida.');
        }
    }
}
