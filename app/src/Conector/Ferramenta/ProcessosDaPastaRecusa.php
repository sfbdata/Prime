<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Exception\ProcessoForaDoEscopoException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Traduz o que os UseCases de pasta e processo recusam para a recusa fixa do conector ({@see ErroDaFerramenta}, 6.2):
 * posse errada vira "não encontrado" e a regra de negócio vira a mensagem que o próprio UseCase escreve (todas fixas,
 * sem nome nem valor do banco). Qualquer outra exceção (banco) sobe como está: é falha, não recusa.
 */
final class ProcessosDaPastaRecusa
{
    public const MOTIVO_MUDOU = 'mudou_depois_da_proposta';
    public const MUDOU = 'O que a proposta mudaria já não é o que está no BlueJus. Faça o pedido de novo.';

    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     *
     * @throws ErroDaFerramenta
     */
    public static function traduzindo(\Closure $trabalho): mixed
    {
        try {
            return $trabalho();
        } catch (AccessDeniedException|PastaDeOutroEscritorioException|SemPermissaoParaEditarPastaException|NotaTecnicaForaDoEscopoException|ProcessoForaDoEscopoException) {
            // `ProcessoOcultoPeloCadeadoException` é um `AccessDeniedException`: o mesmo "não encontrado".
            throw ErroDaFerramenta::naoEncontrado();
        } catch (\InvalidArgumentException|\DomainException $e) {
            throw self::recusa($e->getMessage());
        }
    }

    public static function recusa(string $mensagem): ErroDaFerramenta
    {
        return new ErroDaFerramenta(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $mensagem);
    }

    public static function mudou(): ErroDaFerramenta
    {
        return new ErroDaFerramenta(self::MOTIVO_MUDOU, self::MUDOU);
    }
}
