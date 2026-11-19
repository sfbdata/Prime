<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Exception\ChecklistModeloJaExisteException;
use App\Pasta\Exception\ModeloDePecaInvalidoException;
use App\Pasta\Exception\OriginalNaoEncontradoException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Exception\SelecaoAcimaDoTetoException;
use App\Pasta\Exception\TituloDePecaLongoDemaisException;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Traduz o que os UseCases da pasta recusam para a recusa fixa do conector ({@see ErroDaFerramenta}, 6.2): posse
 * errada vira "não encontrado", falta de permissão vira "sem permissão", e a regra de negócio vira a mensagem que o
 * próprio UseCase escreve (todas fixas, sem nome nem valor; a que levaria o nome do arquivo é trocada por uma fixa
 * aqui). Qualquer outra exceção (banco, armazenamento) sobe como está: é falha, não recusa.
 */
final class DocumentosEPecasRecusa
{
    public const MOTIVO_REGRA = 'regra_de_negocio';
    public const MOTIVO_ARQUIVO = 'arquivo_indisponivel';
    public const MOTIVO_JA_EXISTE = 'ja_existe';

    public const ARQUIVO_SUMIDO = 'O arquivo não foi encontrado no armazenamento. Nada foi gravado.';
    public const MODELO_JA_EXISTE = 'Já existe um modelo de checklist com esse nome neste escritório. Escolha outro nome.';

    private function __construct()
    {
    }

    /**
     * Roda o trabalho e devolve o que ele devolve; as recusas do domínio viram {@see ErroDaFerramenta}.
     *
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
        } catch (AccessDeniedException|PastaDeOutroEscritorioException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (SemPermissaoParaEditarPastaException|SemPermissaoParaVerPastaException) {
            throw ErroDaFerramenta::semPermissao();
        } catch (ArquivoNaoEncontrado|OriginalNaoEncontradoException) {
            throw new ErroDaFerramenta(self::MOTIVO_ARQUIVO, self::ARQUIVO_SUMIDO);
        } catch (ChecklistModeloJaExisteException) {
            throw new ErroDaFerramenta(self::MOTIVO_JA_EXISTE, self::MODELO_JA_EXISTE);
        } catch (ModeloDePecaInvalidoException|TituloDePecaLongoDemaisException|SelecaoAcimaDoTetoException $e) {
            throw new ErroDaFerramenta(self::MOTIVO_REGRA, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            if ($e::class !== \InvalidArgumentException::class) {
                throw $e;
            }

            throw new ErroDaFerramenta(self::MOTIVO_REGRA, $e->getMessage());
        }
    }
}
