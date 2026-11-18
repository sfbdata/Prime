<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Quem executa, depois do "sim" da pessoa, a ação que uma ferramenta de gravação devolveu como proposta
 * (`docs/specs/mcp-remoto-oauth.md`, 6.6 e 6.7). Toda ferramenta de gravação que propõe (exclusão, gravar por cima,
 * cadastrar cliente, voltar ou reativar fase) implementa também esta interface; a `confirmar_acao` acha o executor pelo
 * nome da ferramenta guardado na proposta.
 *
 * Contrato (o mesmo para os PRs 10 a 13):
 *
 *  1. A proposta nasce pelo {@see \App\Conector\Service\PropostasDoConector::propor()}, com a ação igual à chave da
 *     {@see TabelaDeAbaEChave} (o valor do campo de {@see TabelaDeAbaEChave::campoDasAcoes()}: `lugar` na
 *     `registrar_na_pasta`) e os dados exatos do que será feito. A ação fina (ex.: `editar`, `excluir`) mora nos dados.
 *  2. {@see reconferir()} roda dentro da transação que segura a proposta travada e confere DE NOVO tudo o que a
 *     ferramenta conferiu ao propor (6.7, item 4): a linha da tabela com a pasta (`GuardaDaAcaoDoConector`), o recurso
 *     de editar, o teto (6.3, item 4), o segredo de justiça, o Financeiro, a pasta na lixeira, o envio automático ao
 *     cliente, o CPF que virou repetido. Nunca grava nada: recusa com {@see ErroDaFerramenta} de mensagem fixa.
 *  3. {@see executarConfirmada()} roda na mesma transação, logo depois, e grava pelos UseCases, como a tela. Num lote
 *     (6.7, item 5), cada item que falha entra no resultado com o motivo, sem lançar; o executor não usa
 *     `EntityManager::wrapInTransaction` por item (ele fecha o EntityManager na falha), e sim um ponto de retorno da
 *     conexão. Lançar daqui desfaz TUDO o que a confirmação gravou e a proposta continua pendente.
 *
 * Pessoa, escritório e escopo vêm só da {@see ChamadaDoConector} da confirmação, nunca da proposta: a
 * `confirmar_acao` já conferiu que são os mesmos.
 */
#[AutoconfigureTag(self::TAG)]
interface ExecutorDeProposta
{
    public const TAG = 'conector.executor_de_proposta';

    /** O nome da ferramenta de gravação cujas propostas este executor confirma (uma da 6.6). */
    public function ferramenta(): string;

    /**
     * Confere de novo, sem gravar, que a ação ainda pode acontecer agora, para esta pessoa.
     *
     * @param array<string, mixed> $dados os dados exatos da proposta
     *
     * @throws ErroDaFerramenta
     */
    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void;

    /**
     * Grava a ação confirmada.
     *
     * @param array<string, mixed> $dados os dados exatos da proposta
     *
     * @throws ErroDaFerramenta recusa de última hora; desfaz tudo e a proposta fica pendente
     */
    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao;
}
