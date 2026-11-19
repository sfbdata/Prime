<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Cliente\Service\CamposDoCadastro;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\PropostasDoConector;

/**
 * A porta da `atualizar_cliente` para o {@see PropostasDoConector::propor()} (`docs/specs/mcp-remoto-oauth.md`, 6.7,
 * item 1): o cadastro em lote, a troca de valor preenchido e o desvincular viram proposta por aqui.
 *
 * Antes de propor, confere os tetos do resumo e dos dados: um lote de 50 clientes com todos os campos no tamanho
 * máximo passa deles, e o `propor()` trataria isso como erro de programação. Aqui vira recusa de mensagem fixa, sem
 * proposta.
 *
 * O resumo leva os valores que o assistente mandou, para a pessoa conferir (6.2, regra 4, a exceção do cadastro e do
 * completar), e nunca um valor que já está no banco: {@see campo()} recebe só o valor novo.
 */
final class AtualizarClienteProposta
{
    public const GRANDE_DEMAIS = 'O pedido ficou grande demais para uma confirmação só. Mande menos clientes ou menos campos por vez. Nada foi gravado.';

    /** As mesmas opções do JSON que o {@see PropostasDoConector} mede. */
    private const JSON = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(private readonly PropostasDoConector $propostas)
    {
    }

    /**
     * @param array<string, mixed> $dados       o que a confirmação fará, exatamente (com `operacao`, a ação fina)
     * @param array<string, mixed> $complemento vai à resposta, em `detalhes`; não fica na proposta
     *
     * @throws ErroDaFerramenta
     */
    public function propor(ChamadaDoConector $chamada, string $acao, array $dados, string $resumo, array $complemento = []): RespostaDaFerramenta
    {
        if (mb_strlen(PropostasDoConector::normalizarResumo($resumo)) > PropostasDoConector::TETO_DO_RESUMO
            || \strlen(json_encode($dados, self::JSON)) > PropostasDoConector::TETO_DOS_DADOS) {
            throw ErroDaFerramenta::recusada(self::GRANDE_DEMAIS);
        }

        return $this->propostas->propor($chamada, AtualizarClienteFerramenta::NOME, $acao, $dados, $resumo, $complemento);
    }

    /**
     * Uma linha do resumo: o rótulo do campo, a marca (ex.: "troca o valor atual") e o valor que o assistente mandou;
     * vazio é apagar.
     *
     * @param string $chave chave de {@see CamposDoCadastro::ROTULOS} ou de {@see AtualizarClienteFerramenta::CAMPOS_DO_CONTATO}
     */
    public static function campo(string $chave, string $valor, string $marca = ''): string
    {
        $rotulo = CamposDoCadastro::ROTULOS[$chave] ?? $chave;

        return $rotulo . ($marca === '' ? '' : ' (' . $marca . ')') . ': ' . (trim($valor) === '' ? 'apagar o valor atual' : trim($valor));
    }

    /**
     * O inteiro que a própria ferramenta guardou na proposta.
     *
     * @param array<string, mixed> $dados
     *
     * @throws \LogicException proposta que esta ferramenta não gravou
     */
    public static function inteiroDe(array $dados, string $chave): int
    {
        $valor = $dados[$chave] ?? null;

        return \is_int($valor) ? $valor : throw new \LogicException(sprintf('Proposta sem o campo %s.', $chave));
    }

    /**
     * O texto que a própria ferramenta guardou na proposta.
     *
     * @param array<string, mixed> $dados
     *
     * @throws \LogicException
     */
    public static function textoDe(array $dados, string $chave): string
    {
        $valor = $dados[$chave] ?? null;

        return \is_string($valor) ? $valor : throw new \LogicException(sprintf('Proposta sem o campo %s.', $chave));
    }

    /**
     * Os campos (chave e texto) que a própria ferramenta guardou na proposta.
     *
     * @param array<string, mixed> $dados
     *
     * @return array<string, string>
     *
     * @throws \LogicException
     */
    public static function camposDe(array $dados, string $chave): array
    {
        $valor = $dados[$chave] ?? null;
        if (!\is_array($valor) || $valor === [] || array_is_list($valor) || array_diff_key($valor, CamposDoCadastro::ROTULOS) !== []) {
            throw new \LogicException(sprintf('Proposta sem o campo %s.', $chave));
        }

        return array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $valor);
    }
}
