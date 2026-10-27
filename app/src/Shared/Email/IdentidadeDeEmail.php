<?php

declare(strict_types=1);

namespace App\Shared\Email;

use App\Shared\Contract\ConfiguracaoDaPlataforma;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface;

/**
 * Identidade dos e-mails do sistema (spec `login-config-identidade.md`): remetente, resposta e prefixo do assunto.
 *
 * O ambiente (MAILER_FROM, MAILER_REPLY_TO) é a base e a verdade de quando não há configuração. Por cima, a cada
 * chamada (nunca no construtor: o Master muda sem reiniciar), valem o `nome` (nome de exibição do From e prefixo do
 * assunto) e o `remetente` (endereço) das configurações globais. Valor inválido, vazio ou falha do leitor caem no
 * ambiente, sem lançar. O Reply-To continua só do ambiente.
 */
final class IdentidadeDeEmail
{
    private const NOME_PADRAO = 'BlueJus';
    private const NOME_MAX = 60;
    private const ENDERECO_MAX = 180;

    private readonly Address $from;
    private readonly ?Address $replyTo;

    public function __construct(
        string $mailerFrom,
        string $mailerReplyTo,
        private readonly ?ConfiguracaoDaPlataforma $configuracao = null,
    ) {
        $this->from = $this->validarEndereco($mailerFrom, 'MAILER_FROM');

        $mailerReplyTo = trim($mailerReplyTo);
        $this->replyTo = $mailerReplyTo === ''
            ? null
            : $this->validarEndereco($mailerReplyTo, 'MAILER_REPLY_TO');
    }

    /** O remetente como o destinatário o vê ("Nome <endereço>"), para as telas que mostram o e-mail (Comercial, §10). */
    public function remetente(): string
    {
        $from = $this->fromVigente();
        $nome = $from->getName();

        return $nome === '' ? $from->getAddress() : $nome . ' <' . $from->getAddress() . '>';
    }

    public function aplicar(Email $email, string $assunto): Email
    {
        $from = $this->fromVigente();
        $nome = $from->getName() !== '' ? $from->getName() : self::NOME_PADRAO;
        $prefixo = '[' . $nome . ']';

        $assunto = trim($assunto);
        if (str_starts_with($assunto, '[' . self::NOME_PADRAO . ']') && $prefixo !== '[' . self::NOME_PADRAO . ']') {
            // Assunto já montado com o prefixo antigo: troca pelo do nome configurado, sem empilhar dois.
            $assunto = trim(substr($assunto, \strlen(self::NOME_PADRAO) + 2));
        }
        if (!str_starts_with($assunto, $prefixo)) {
            $assunto = $prefixo . ' ' . $assunto;
        }

        $email
            ->from($from)
            ->subject($assunto);

        if ($this->replyTo !== null) {
            $email->replyTo($this->replyTo);
        }

        return $email;
    }

    /** O From do ambiente com o nome e o endereço configurados por cima, quando válidos. Nunca lança. */
    private function fromVigente(): Address
    {
        $nome = $this->nomeConfigurado() ?? $this->from->getName();
        $endereco = $this->enderecoConfigurado() ?? $this->from->getAddress();

        try {
            return new Address($endereco, $nome);
        } catch (\Throwable) {
            return $this->from;
        }
    }

    private function nomeConfigurado(): ?string
    {
        $nome = trim($this->ler('nome'));
        if ($nome === '' || mb_strlen($nome) > self::NOME_MAX || preg_match('/[\x00-\x1F\x7F<>"\\\\\[\]]/u', $nome) === 1) {
            return null;
        }

        return $nome;
    }

    private function enderecoConfigurado(): ?string
    {
        $endereco = trim($this->ler('remetente'));
        if ($endereco === '' || mb_strlen($endereco) > self::ENDERECO_MAX || filter_var($endereco, \FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $endereco;
    }

    private function ler(string $chave): string
    {
        if ($this->configuracao === null) {
            return '';
        }
        try {
            return $this->configuracao->valor($chave);
        } catch (\Throwable) {
            return '';
        }
    }

    private function validarEndereco(string $endereco, string $variavel): Address
    {
        try {
            return Address::create($endereco);
        } catch (ExceptionInterface) {
            throw new \InvalidArgumentException(
                sprintf('Configuração de e-mail inválida em %s.', $variavel),
            );
        }
    }
}
