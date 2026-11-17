<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Uma ferramenta do conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 6). Toda implementação ganha a tag
 * {@see TAG} e é registrada no SDK pelo despacho único, que confere a requisição aberta pelo portão, recusa campo fora
 * do esquema e monta a resposta (6.2, regras 1, 7 e 10). O nome precisa estar no {@see CatalogoFechadoDoConector}.
 *
 * A ferramenta nunca lê pessoa, escritório ou escopo de sessão, Security ou Request: tudo vem da
 * {@see ChamadaDoConector} (4.2).
 */
#[AutoconfigureTag(self::TAG)]
interface FerramentaDoConector
{
    public const TAG = 'conector.ferramenta';

    /** O nome no `tools/list`, um dos de {@see CatalogoFechadoDoConector::FERRAMENTAS}. */
    public function nome(): string;

    /** O `title`, em português; o mesmo de {@see CatalogoFechadoDoConector::TITULOS}. */
    public function titulo(): string;

    /** O texto que o modelo lê para escolher a ferramenta. */
    public function descricao(): string;

    /**
     * O JSON Schema da entrada: plano, com `acao` em lista fechada quando houver ações e `additionalProperties: false`,
     * sem `oneOf` (6.2, regra 10).
     *
     * @return array<string, mixed>
     */
    public function esquemaDeEntrada(): array;

    /**
     * Executa com os argumentos já conferidos contra o esquema.
     *
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta recusa com mensagem fixa, que vira `isError` na resposta
     */
    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta;
}
