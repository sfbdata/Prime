<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\CadastroInvalidoException;
use App\Cliente\Exception\DocumentoJaCadastradoException;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\UseCase\CriarClienteAvulsoUseCase;
use App\Cliente\UseCase\CriarClienteDaPastaUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\Pasta;
use App\Service\PermissionChecker;
use App\Shared\Service\DigitoVerificador;

/**
 * `cadastrar` da `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6, e 6.7, item 5): uma lista de 1 a 50
 * clientes vira UMA proposta, e uma confirmação grava a lista toda. Cadastrar é sempre por proposta.
 *
 * Cada item tem `tipo` (`pf` ou `pj`), `campos` (chaves de {@see CamposDoCadastro::ROTULOS}) e `pasta_id` opcional.
 * Com pasta, {@see CriarClienteDaPastaUseCase} (editar a pasta, que passa pelas recusas da linha `cadastrar` com pasta,
 * da lixeira e do segredo, e o cliente fica ligado a ela); sem pasta, {@see CriarClienteAvulsoUseCase}, que exige
 * `resources.carteira.gerenciar`, como o Chat I.A faz no `ConversorDaProposta`.
 *
 * A mesma conferência ({@see conferir()}) roda ao propor, ao reconferir e antes de gravar, item por item e sem gravar:
 * sai da lista, com o motivo, o item sem permissão (ou cuja pasta não se acha), o de valor que o cadastro recusa, o de
 * CPF ou CNPJ que já é de um cadastro do escritório (com o id e o NOME dele) e o que repete o CPF ou CNPJ de um item
 * anterior da mesma lista (só o primeiro fica). Ao propor, os que saíram vão em `detalhes.fora_da_lista`; se nada sobra,
 * não há proposta. Na confirmação, os que deixaram de passar (CPF que virou repetido, pasta que foi para a lixeira)
 * entram no resultado como recusados, sem lançar; se nenhum passa, a confirmação recusa e nada é gravado.
 *
 * O resultado traz os ids e os nomes criados, nunca outro valor do cadastro (6.2, regra 4).
 */
final class AtualizarClienteLote
{
    public const MAXIMO = 50;
    public const TIPOS = ['pf', 'pj'];

    public const LISTA_FORA_DO_TAMANHO = 'Mande de 1 a 50 clientes por pedido. Nada foi gravado.';
    public const NADA_A_CADASTRAR = 'Nenhum cliente da lista pode ser cadastrado: veja em fora_da_lista o motivo de cada um. Nada foi gravado e não há proposta.';
    public const NADA_PASSA_NA_CONFIRMACAO = 'Nenhum cliente da proposta pode mais ser cadastrado: o CPF ou CNPJ passou a ser de outro cadastro, ou a permissão ou a pasta mudou. Nada foi gravado. Peça o cadastro de novo para ver o motivo de cada um.';
    public const JA_CADASTRADO = 'Este CPF ou CNPJ já é do cadastro do escritório indicado em cadastro_existente.';
    public const REPETIDO_NA_LISTA = 'Este CPF ou CNPJ repete o de um item anterior da lista (igual_ao_item): só o primeiro fica.';
    public const CADASTRO_RECUSADO = 'O BlueJus recusou os valores deste cliente. Confira CPF ou CNPJ válidos, e-mail, nascimento AAAA-MM-DD, UF de duas letras, estado civil da lista e o tamanho de cada campo.';

    public const MOTIVO_JA_CADASTRADO = 'ja_cadastrado';
    public const MOTIVO_REPETIDO_NA_LISTA = 'repetido_na_lista';
    public const MOTIVO_CADASTRO_RECUSADO = 'cadastro_recusado';

    public function __construct(
        private readonly AtualizarClienteAcesso $acesso,
        private readonly AtualizarClienteProposta $proposta,
        private readonly CriarClienteDaPastaUseCase $criarDaPasta,
        private readonly CriarClienteAvulsoUseCase $criarAvulso,
        private readonly PermissionChecker $permissoes,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    /**
     * @param list<array{tipo: string, campos: array<string, string>, pasta_id: int|null}> $clientes já conferidos pela ferramenta
     *
     * @throws ErroDaFerramenta
     */
    public function propor(array $clientes, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $itens = [];
        foreach ($clientes as $i => $cliente) {
            $itens[] = ['item' => $i + 1] + $cliente;
        }
        ['passam' => $passam, 'fora' => $fora] = $this->conferir($itens, $chamada);
        if ($passam === []) {
            return new RespostaDaFerramenta(
                ['estado' => 'recusada', 'acao' => 'cadastrar', 'mensagem' => self::NADA_A_CADASTRAR, 'fora_da_lista' => $fora],
                contagens: ['fora_da_lista' => \count($fora)],
            );
        }

        $dados = ['operacao' => 'cadastrar', 'clientes' => array_map(static fn (array $p): array => $p['item'], $passam)];

        return $this->proposta->propor($chamada, 'cadastrar', $dados, $this->resumo($passam, $chamada), $fora === [] ? [] : ['fora_da_lista' => $fora]);
    }

    /**
     * Reconfere a lista inteira (6.7, item 4); recusa só se nenhum item passa. Não grava nada.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function reconferir(array $dados, ChamadaDoConector $chamada): void
    {
        $this->quemPassa($dados, $chamada);
    }

    /**
     * Confere cada item antes de gravar qualquer um e grava só os que passaram (contrato da {@see ExecutorDeProposta},
     * item 3). Exceção ao gravar (inclusive de banco) desfaz o lote inteiro.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function executarConfirmada(array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        ['passam' => $passam, 'fora' => $fora] = $this->quemPassa($dados, $chamada);
        $criados = [];
        foreach ($passam as ['item' => $item, 'pasta' => $pasta]) {
            $cliente = $this->gravar($item, $pasta, $chamada);
            $criados[] = ['item' => $item['item'], 'id' => (int) $cliente->getId(), 'nome' => $this->nome($cliente, $chamada), 'pasta_id' => $pasta?->getId()];
        }
        $ids = array_map(static fn (array $c): int => $c['id'], $criados);

        return new ResultadoDaConfirmacao(
            ['criados' => $criados, 'recusados' => $fora],
            idsDoResultado: $ids,
            contagens: ['criados' => \count($criados), 'recusados' => \count($fora)],
            ids: ['cliente' => $ids],
        );
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{passam: non-empty-list<array{item: array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null}, pasta: Pasta|null}>, fora: list<array<string, mixed>>}
     *
     * @throws ErroDaFerramenta nenhum item passa
     */
    private function quemPassa(array $dados, ChamadaDoConector $chamada): array
    {
        $conferencia = $this->conferir(self::itensDos($dados), $chamada);
        if ($conferencia['passam'] === []) {
            $motivo = $conferencia['fora'][0]['motivo'] ?? ErroDaFerramenta::MOTIVO_RECUSADA;

            throw new ErroDaFerramenta(\is_string($motivo) ? $motivo : ErroDaFerramenta::MOTIVO_RECUSADA, self::NADA_PASSA_NA_CONFIRMACAO);
        }

        return $conferencia;
    }

    /**
     * A conferência de cada item, na ordem, sem gravar; o CPF ou CNPJ de um item que passou conta para os seguintes.
     *
     * @param list<array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null}> $itens
     *
     * @return array{passam: list<array{item: array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null}, pasta: Pasta|null}>, fora: list<array<string, mixed>>}
     */
    private function conferir(array $itens, ChamadaDoConector $chamada): array
    {
        $vistos = [];
        $passam = [];
        $fora = [];
        foreach ($itens as $item) {
            $documento = self::documento($item);
            if ($documento !== '' && isset($vistos[$documento])) {
                $fora[] = self::recusa($item, self::MOTIVO_REPETIDO_NA_LISTA, self::REPETIDO_NA_LISTA) + ['igual_ao_item' => $vistos[$documento]];
                continue;
            }
            [$recusa, $pasta] = $this->conferirItem($item, $chamada);
            if ($recusa !== null) {
                $fora[] = $recusa;
                continue;
            }
            if ($documento !== '') {
                $vistos[$documento] = $item['item'];
            }
            $passam[] = ['item' => $item, 'pasta' => $pasta];
        }

        return ['passam' => $passam, 'fora' => $fora];
    }

    /**
     * @param array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null} $item
     *
     * @return array{0: array<string, mixed>|null, 1: Pasta|null} a recusa (ou null) e a pasta conferida
     */
    private function conferirItem(array $item, ChamadaDoConector $chamada): array
    {
        try {
            $pasta = $this->autorizar($item['pasta_id'], $chamada);
            $this->acesso->conferirCampos($item['tipo'], $item['campos'], $chamada->contexto->escritorio);

            return [null, $pasta];
        } catch (ErroDaFerramenta $erro) {
            return [self::recusa($item, $erro->motivo, $erro->getMessage()), null];
        } catch (DocumentoJaCadastradoException $erro) {
            $existente = ['id' => (int) $erro->existente->getId(), 'nome' => $this->nome($erro->existente, $chamada)];

            return [self::recusa($item, self::MOTIVO_JA_CADASTRADO, self::JA_CADASTRADO) + ['cadastro_existente' => $existente], null];
        } catch (CadastroInvalidoException|\DomainException) {
            return [self::recusa($item, self::MOTIVO_CADASTRO_RECUSADO, self::CADASTRO_RECUSADO), null];
        }
    }

    /**
     * Com pasta: a pasta viva que a pessoa edita, pela linha `cadastrar` com pasta. Sem pasta: a capacidade do
     * {@see CriarClienteAvulsoUseCase}.
     *
     * @throws ErroDaFerramenta
     */
    private function autorizar(?int $pastaId, ChamadaDoConector $chamada): ?Pasta
    {
        if ($pastaId !== null) {
            return $this->acesso->pastaViva($chamada, 'cadastrar', $pastaId, true);
        }
        $contexto = $chamada->contexto;
        if (!$this->permissoes->hasPermission($contexto->pessoa, $contexto->escritorio, CriarClienteAvulsoUseCase::CAPACIDADE)) {
            throw ErroDaFerramenta::semPermissao();
        }

        return null;
    }

    /**
     * @param array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null} $item
     *
     * @throws ErroDaFerramenta
     */
    private function gravar(array $item, ?Pasta $pasta, ChamadaDoConector $chamada): Cliente
    {
        $contexto = $chamada->contexto;

        return AtualizarClienteAcesso::gravando(
            fn (): Cliente => $pasta !== null
                ? $this->criarDaPasta->executar($pasta, $item['tipo'], $item['campos'], $contexto->pessoa, $contexto->escritorio)
                : $this->criarAvulso->executar($item['tipo'], $item['campos'], $contexto->pessoa, $contexto->escritorio),
            self::CADASTRO_RECUSADO,
        );
    }

    /**
     * Uma linha por cliente, com o número do item na lista mandada, o tipo, a pasta e os valores que o assistente
     * mandou (6.2, regra 4, a exceção do cadastro).
     *
     * @param list<array{item: array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null}, pasta: Pasta|null}> $passam
     */
    private function resumo(array $passam, ChamadaDoConector $chamada): string
    {
        $total = \count($passam);
        $linhas = [sprintf('Cadastrar %d %s no escritório:', $total, $total === 1 ? 'cliente' : 'clientes')];
        foreach ($passam as ['item' => $item, 'pasta' => $pasta]) {
            $onde = $pasta === null
                ? 'sem pasta'
                : 'na pasta ' . $this->protecao->rotulo($chamada->contexto->escritorio, (string) $pasta->getNup(), 'sem número');
            $preenchidos = array_filter($item['campos'], static fn (string $valor): bool => trim($valor) !== '');
            $campos = array_map(static fn (string $chave, string $valor): string => AtualizarClienteProposta::campo($chave, $valor), array_keys($preenchidos), array_values($preenchidos));
            $linhas[] = sprintf('%d. %s, %s: %s', $item['item'], $item['tipo'] === 'pj' ? 'Pessoa jurídica' : 'Pessoa física', $onde, $campos === [] ? 'sem nenhum campo preenchido' : implode('; ', $campos));
        }

        return implode("\n", $linhas);
    }

    /**
     * Os itens guardados na proposta, do jeito que o {@see propor()} os gravou.
     *
     * @param array<string, mixed> $dados
     *
     * @return list<array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null}>
     *
     * @throws \LogicException proposta que este executor não gravou
     */
    private static function itensDos(array $dados): array
    {
        $clientes = $dados['clientes'] ?? null;
        if (($dados['operacao'] ?? null) !== 'cadastrar' || !\is_array($clientes) || $clientes === [] || \count($clientes) > self::MAXIMO) {
            throw new \LogicException('Proposta de cadastro sem a lista de clientes.');
        }

        return array_values(array_map(self::itemGuardado(...), $clientes));
    }

    /**
     * @return array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null}
     *
     * @throws \LogicException
     */
    private static function itemGuardado(mixed $item): array
    {
        if (!\is_array($item) || !\is_int($item['item'] ?? null) || !\in_array($item['tipo'] ?? null, self::TIPOS, true)
            || !\is_array($item['campos'] ?? null) || !(($item['pasta_id'] ?? null) === null || \is_int($item['pasta_id']))) {
            throw new \LogicException('Item de cadastro fora do formato da proposta.');
        }
        $campos = [];
        foreach ($item['campos'] as $chave => $valor) {
            $campos[(string) $chave] = \is_scalar($valor) ? (string) $valor : '';
        }

        return ['item' => $item['item'], 'tipo' => (string) $item['tipo'], 'campos' => $campos, 'pasta_id' => $item['pasta_id'] ?? null];
    }

    /**
     * O CPF (pessoa física) ou o CNPJ (jurídica) do item, só os dígitos; vazio quando não veio.
     *
     * @param array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null} $item
     */
    private static function documento(array $item): string
    {
        return DigitoVerificador::digitos($item['campos'][$item['tipo'] === 'pj' ? 'cnpj' : 'cpf'] ?? '');
    }

    /**
     * @param array{item: int, tipo: string, campos: array<string, string>, pasta_id: int|null} $item
     *
     * @return array<string, mixed>
     */
    private static function recusa(array $item, string $motivo, string $mensagem): array
    {
        return ['item' => $item['item'], 'motivo' => $motivo, 'mensagem' => $mensagem];
    }

    private function nome(Cliente $cliente, ChamadaDoConector $chamada): string
    {
        return $this->protecao->rotulo($chamada->contexto->escritorio, $cliente->getNomeExibicao(), 'sem nome');
    }
}
