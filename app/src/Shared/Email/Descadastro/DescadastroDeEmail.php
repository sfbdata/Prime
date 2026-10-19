<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\Repository\EmailDescadastroRepository;
use Psr\Clock\ClockInterface;

/**
 * Implementação do {@see DescadastroDeEmailInterface}: tabela `email_descadastro` (SQL com o tenant
 * explícito, ver o repositório) e o link assinado de {@see LinkDeDescadastro}.
 */
final class DescadastroDeEmail implements DescadastroDeEmailInterface
{
    public function __construct(
        private readonly EmailDescadastroRepository $repositorio,
        private readonly LinkDeDescadastro $link,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function estaDescadastrado(Tenant $tenant, string $email, string $assunto): bool
    {
        $email = self::normalizar($email);
        if ($email === '') {
            return false;
        }

        return $this->repositorio->existe($tenant, $email, self::exigirPadrao($assunto, 'assunto'));
    }

    public function descadastrar(Tenant $tenant, string $email, string $assunto, string $origem): void
    {
        $this->repositorio->registrarSeAusente(
            $tenant,
            self::exigirEmail($email),
            self::exigirPadrao($assunto, 'assunto'),
            self::exigirPadrao($origem, 'origem'),
            $this->relogio->now(),
        );
    }

    public function gerarLink(Tenant $tenant, string $email, string $assunto): string
    {
        return $this->link->gerar(new PedidoDeDescadastro(
            (int) $tenant->getId(),
            self::exigirEmail($email),
            self::exigirPadrao($assunto, 'assunto'),
        ));
    }

    private static function normalizar(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function exigirEmail(string $email): string
    {
        $email = self::normalizar($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
            throw new \InvalidArgumentException('E-mail inválido para o descadastro.');
        }

        return $email;
    }

    private static function exigirPadrao(string $valor, string $campo): string
    {
        if (preg_match(self::PADRAO_DO_ASSUNTO, $valor) !== 1) {
            throw new \InvalidArgumentException(sprintf('O %s do descadastro deve ter de 3 a 40 letras minúsculas ou sublinhado.', $campo));
        }

        return $valor;
    }
}
