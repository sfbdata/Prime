<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Roda um UseCase da tela e troca a exceção de regra dele por recusa de mensagem fixa (`ErroDaFerramenta::recusada`):
 * a mensagem do UseCase nunca passa adiante (ela pode trazer nome de cliente, como a do número já reusado). A escolha é
 * pela CLASSE da exceção. O que não é recusa de regra (erro de banco, programação) segue como está e desfaz a transação.
 */
final class AtualizarPastaRecusa
{
    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $gravar
     *
     * @return T
     *
     * @throws ErroDaFerramenta
     */
    public static function gravando(\Closure $gravar, string $mensagemFixa): mixed
    {
        try {
            return $gravar();
        } catch (PastaDeOutroEscritorioException|SemPermissaoParaEditarPastaException|SemPermissaoParaVerPastaException|AccessDeniedException|NotFoundHttpException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (\DomainException|\InvalidArgumentException) {
            throw ErroDaFerramenta::recusada($mensagemFixa);
        }
    }

    /**
     * Um inteiro dos dados da proposta (que voltam do banco como foram gravados).
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public static function inteiro(array $dados, string $campo): int
    {
        $valor = $dados[$campo] ?? null;

        return \is_int($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /**
     * Um texto (ou nulo) dos dados da proposta.
     *
     * @param array<string, mixed> $dados
     */
    public static function texto(array $dados, string $campo): ?string
    {
        $valor = $dados[$campo] ?? null;

        return \is_string($valor) ? $valor : null;
    }
}
