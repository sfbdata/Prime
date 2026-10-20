<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\Repository\EmailDescadastroRepository;
use Psr\Clock\ClockInterface;

/**
 * Implementação do {@see DescadastroDeEmailInterface}: tabela `email_descadastro` (SQL com o escopo
 * explícito, escritório ou plataforma, ver o repositório) e o link assinado de {@see LinkDeDescadastro}.
 */
final class DescadastroDeEmail implements DescadastroDeEmailInterface
{
    public function __construct(
        private readonly EmailDescadastroRepository $repositorio,
        private readonly LinkDeDescadastro $link,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function estaDescadastrado(?Tenant $tenant, string $email, string $assunto): bool
    {
        $email = self::normalizar($email);
        if ($email === '') {
            return false;
        }

        return $this->repositorio->bloqueado($tenant, $email, self::exigirPadrao($assunto, 'assunto'), self::ASSUNTO_TODOS);
    }

    public function descadastrar(?Tenant $tenant, string $email, string $assunto, string $origem, ?string $motivo = null): void
    {
        $this->repositorio->registrarSeAusente(
            $tenant,
            self::exigirEmail($email),
            self::exigirPadrao($assunto, 'assunto'),
            self::exigirPadrao($origem, 'origem'),
            self::motivo($motivo),
            $this->relogio->now(),
        );
    }

    /**
     * (a) encerra o ativo do assunto; (b) `todos` encerra todos os ativos do escopo; (c) se, depois
     * disso, um `todos` ativo ainda bloqueia o assunto, grava a exceção dele (linha já reativada).
     * Já liberado: nada muda (idempotente).
     */
    public function reativar(?Tenant $tenant, string $email, string $assunto, string $origem, ?User $por = null): void
    {
        $email = self::exigirEmail($email);
        $assunto = self::exigirPadrao($assunto, 'assunto');
        $origem = self::exigirPadrao($origem, 'origem');
        $porId = $por?->getId();
        $agora = $this->relogio->now();

        $this->repositorio->encerrarAtivos($tenant, $email, $assunto === self::ASSUNTO_TODOS ? null : $assunto, $origem, $porId, $agora);
        if ($assunto !== self::ASSUNTO_TODOS && $this->repositorio->bloqueado($tenant, $email, $assunto, self::ASSUNTO_TODOS)) {
            $this->repositorio->registrarExcecao($tenant, $email, $assunto, $origem, $porId, $agora);
        }
    }

    public function listar(?Tenant $tenant, ?string $busca, int $pagina, int $porPagina): ListaDeDescadastros
    {
        $pagina = max(1, $pagina);
        $porPagina = min(self::MAXIMO_POR_PAGINA, max(1, $porPagina));
        $resultado = $this->repositorio->listarAtivos($tenant, $busca, $pagina, $porPagina);

        return new ListaDeDescadastros(
            $resultado['total'],
            array_map(static fn (array $l): ItemDeDescadastro => new ItemDeDescadastro($l['email'], $l['assunto'], $l['origem'], $l['motivo'], new \DateTimeImmutable($l['em'])), $resultado['linhas']),
            $pagina,
            $porPagina,
        );
    }

    public function gerarLink(?Tenant $tenant, string $email, string $assunto): string
    {
        return $this->link->gerar(new PedidoDeDescadastro(
            $tenant === null ? null : (int) $tenant->getId(),
            self::exigirEmail($email),
            self::exigirPadrao($assunto, 'assunto'),
        ));
    }

    private static function motivo(?string $motivo): ?string
    {
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) > 280) {
            throw new \InvalidArgumentException('O motivo do descadastro tem no máximo 280 caracteres.');
        }

        return $motivo === '' ? null : $motivo;
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
