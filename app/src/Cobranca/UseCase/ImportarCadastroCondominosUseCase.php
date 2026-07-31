<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AdicionarEmailPessoaInput;
use App\Cobranca\DTO\AdicionarEnderecoPessoaInput;
use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\DTO\CriarObjetoInput;
use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\Service\Importacao\CondominoImportavel;
use App\Cobranca\Service\Importacao\PessoaEmImportacao;
use App\Cobranca\Service\Importacao\ResultadoImportacaoCadastro;
use App\Cobranca\Service\Importacao\ResultadoLeituraCadastro;
use App\Cobranca\Service\NormalizadorNome;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa o cadastro de condôminos numa Carteira (spec `docs/specs/cobranca-importar-cadastro-condominos.md`).
 * Fonte-agnóstico: recebe `ResultadoLeituraCadastro` já normalizado por um adapter.
 *
 * NÃO escreve regra de negócio nova — orquestra os UseCases que já existem
 * (`CriarObjetoUseCase`, `CriarPessoaVinculadaAoObjetoUseCase`, `Adicionar*PessoaUseCase`), de modo que
 * uma pessoa importada é indistinguível de uma cadastrada pela tela.
 *
 * Duas invariantes que valem a pena repetir aqui, porque são o que torna a reimportação mensal segura:
 *
 * 1. **Aditivo, nunca destrutivo** (§5.2). Contato novo entra na lista e vira o atual; o anterior fica no
 *    histórico. Nada é sobrescrito — nem nome, nem documento, nem contato.
 * 2. **Idempotente.** Rodar duas vezes o mesmo arquivo não duplica objeto, pessoa, vínculo nem contato:
 *    objeto por identificação na carteira, pessoa por documento no tenant, vínculo por (pessoa, objeto)
 *    aberto, e contato por valor já presente na lista.
 *
 * Dedup de pessoa em dois níveis (§5.6): por DOCUMENTO no tenant; **sem documento**, por NOME dentro do
 * OBJETO, entre quem já tem vínculo aberto ali (a "decisão A" que o importador de inadimplência usa).
 * Nunca por nome no tenant inteiro — isso fundiria homônimos, falha já corrigida neste domínio.
 *
 * 3. **A prévia é a confirmação sem escrita** (`processar()`, `$usuario === null`). Ver o porquê lá: as
 *    duas já foram escritas separadas e divergiram na planilha real.
 */
final class ImportarCadastroCondominosUseCase
{
    public function __construct(
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly PessoaRepository $pessoaRepository,
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
        private readonly CriarObjetoUseCase $criarObjeto,
        private readonly CriarPessoaVinculadaAoObjetoUseCase $criarPessoaVinculada,
        private readonly VincularPessoaAObjetoUseCase $vincular,
        private readonly AdicionarTelefonePessoaUseCase $adicionarTelefone,
        private readonly AdicionarEmailPessoaUseCase $adicionarEmail,
        private readonly AdicionarEnderecoPessoaUseCase $adicionarEndereco,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Dry-run: projeta o que aconteceria, sem persistir. Read-only.
     *
     * O que ela promete é literalmente o que `confirmar()` faz — as duas percorrem `processar()`.
     */
    public function prever(int $carteiraId, ResultadoLeituraCadastro $leitura, Tenant $tenant): ResultadoImportacaoCadastro
    {
        return $this->processar($carteiraId, $leitura, $tenant, null);
    }

    /** Persiste numa transação única (ou tudo, ou nada). */
    public function confirmar(int $carteiraId, ResultadoLeituraCadastro $leitura, Tenant $tenant, User $user): ResultadoImportacaoCadastro
    {
        return $this->em->wrapInTransaction(
            fn (): ResultadoImportacaoCadastro => $this->processar($carteiraId, $leitura, $tenant, $user),
        );
    }

    /**
     * Prévia e confirmação percorrem ESTE mesmo método — `$usuario === null` é o dry-run. O mesmo padrão
     * do `ImportarAcordosDetalhadosUseCase`, e pela mesma razão: aqui havia duas implementações da mesma
     * decisão, e elas divergiram na planilha real de 31/07 (a prévia prometeu 215 vínculos onde a
     * confirmação abriu 242, e 292 telefones onde ela acrescentou 290). Nenhum teste pegava, porque
     * ambas estavam internamente coerentes — só discordavam entre si.
     *
     * A decisão de cada linha é tomada UMA vez e usada para as duas coisas: contar e (se houver usuário)
     * escrever. O estado do que já foi visto no próprio arquivo vive em `PessoaEmImportacao` e em
     * `$objetos`, para que a projeção enxergue o que as linhas anteriores fariam — sem isso a mesma
     * pessoa em duas unidades some da prévia, que foi exatamente o defeito.
     */
    private function processar(int $carteiraId, ResultadoLeituraCadastro $leitura, Tenant $tenant, ?User $usuario): ResultadoImportacaoCadastro
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        $objetosCriados = [];
        $pessoasCriadas = [];
        $vinculosCriados = [];
        $reaproveitadas = 0;
        $telefones = 0;
        $emails = 0;
        $enderecos = 0;

        /** @var array<string, ObjetoCobranca|null> $objetos unidade → objeto (null quando o dry-run só o projetou) */
        $objetos = [];
        /** @var array<string, PessoaEmImportacao> $pessoas */
        $pessoas = [];

        foreach ($leitura->importaveis as $condomino) {
            $objeto = $this->resolverObjeto($carteira, $condomino, $tenant, $usuario, $objetos, $objetosCriados);

            $chave = $this->chavePessoa($condomino);
            $conhecida = isset($pessoas[$chave]);
            $estado = $pessoas[$chave] ??= $this->estadoDaPessoa($tenant, $condomino, $objeto);

            if (!$conhecida && $estado->nasceuNestaImportacao()) {
                $pessoasCriadas[] = $condomino->nome;
            } else {
                ++$reaproveitadas;
            }

            $abreVinculo = !$this->temVinculoAberto($estado, $condomino->unidade, $objeto);
            $telefonesNovos = $estado->telefonesAusentes($condomino);
            $emailNovo = $estado->emailAusente($condomino);
            $enderecoNovo = $estado->enderecoAusente($condomino);

            if ($abreVinculo) {
                $vinculosCriados[] = $condomino->unidade . ' → ' . $condomino->nome;
            }
            $telefones += count($telefonesNovos);
            $emails += $emailNovo ? 1 : 0;
            $enderecos += $enderecoNovo ? 1 : 0;

            if ($usuario !== null) {
                $this->aplicar($estado, $condomino, $objeto, $abreVinculo, $telefonesNovos, $emailNovo, $enderecoNovo, $tenant, $usuario);
            }

            // O estado projetado avança nos DOIS modos — é o que faz a linha seguinte decidir igual.
            if ($abreVinculo) {
                $estado->registrarVinculo($condomino->unidade);
            }
            foreach ($telefonesNovos as $numero) {
                $estado->registrarTelefone($numero);
            }
            if ($emailNovo) {
                $estado->registrarEmail((string) $condomino->email);
            }
            if ($enderecoNovo) {
                $estado->registrarEndereco($condomino->endereco);
            }
        }

        return new ResultadoImportacaoCadastro(
            $objetosCriados,
            $pessoasCriadas,
            $vinculosCriados,
            $telefones,
            $emails,
            $enderecos,
            $reaproveitadas,
            $leitura->rejeitadas,
            $leitura->linhasIgnoradas,
        );
    }

    /**
     * A ESCRITA, e só ela — as decisões já vieram tomadas de `processar()`. Nada aqui pode contar nem
     * decidir: se contasse, voltaria a existir uma segunda opinião sobre o que a linha faz.
     *
     * @param list<string> $telefonesNovos
     */
    private function aplicar(
        PessoaEmImportacao $estado,
        CondominoImportavel $condomino,
        ?ObjetoCobranca $objeto,
        bool $abreVinculo,
        array $telefonesNovos,
        bool $emailNovo,
        bool $enderecoNovo,
        Tenant $tenant,
        User $usuario,
    ): void {
        if ($objeto === null) {
            // Impossível por construção: com usuário, `resolverObjeto` cria a unidade que faltava.
            throw new \LogicException(sprintf('Unidade "%s" não resolvida na confirmação.', $condomino->unidade));
        }

        $pessoa = $estado->pessoa();
        if ($pessoa === null) {
            // Nasce já vinculada, com o primeiro telefone, o e-mail e o endereço — um único UseCase,
            // as mesmas regras da tela.
            $vinculo = $this->criarPessoaVinculada->executar(
                $this->inputPessoaVinculada($condomino, $telefonesNovos[0] ?? null),
                (int) $objeto->getId(),
                $tenant,
                $usuario,
            );
            $pessoa = $vinculo->getPessoa();
            $estado->definirPessoa($pessoa);

            $telefonesNovos = array_slice($telefonesNovos, 1); // o primeiro já entrou no cadastro
            $emailNovo = false;
            $enderecoNovo = false;
        } elseif ($abreVinculo) {
            $this->vincular->executar($this->inputVinculo($condomino, $pessoa, $objeto), $tenant, $usuario);
        }

        foreach ($telefonesNovos as $numero) {
            $input = new AdicionarTelefonePessoaInput();
            $input->pessoaId = (int) $pessoa->getId();
            $input->numero = $numero;
            $this->adicionarTelefone->executar($input, $tenant, $usuario);
        }

        if ($emailNovo) {
            $input = new AdicionarEmailPessoaInput();
            $input->pessoaId = (int) $pessoa->getId();
            $input->email = $condomino->email;
            $this->adicionarEmail->executar($input, $tenant, $usuario);
        }

        if ($enderecoNovo) {
            $this->acrescentarEndereco($pessoa, $condomino, $tenant, $usuario);
        }
    }

    /**
     * Identidade da pessoa DENTRO desta execução. Espelha exatamente o casamento do §5.6: documento no
     * tenant, ou nome dentro da unidade. Precisa espelhar — se a chave fosse mais larga que o casamento,
     * a prévia fundiria pessoas que a confirmação criaria separadas.
     */
    private function chavePessoa(CondominoImportavel $condomino): string
    {
        $documento = $condomino->documento();
        if ($documento !== null) {
            return 'doc:' . $documento;
        }

        return 'nome:' . $condomino->unidade . '|' . (NormalizadorNome::normalizar($condomino->nome) ?? mb_strtolower($condomino->nome));
    }

    private function estadoDaPessoa(Tenant $tenant, CondominoImportavel $condomino, ?ObjetoCobranca $objeto): PessoaEmImportacao
    {
        $pessoa = $this->pessoaCadastrada($tenant, $condomino, $objeto);

        return $pessoa !== null ? PessoaEmImportacao::existente($pessoa) : PessoaEmImportacao::nova();
    }

    /**
     * Resolve a unidade. Com usuário, cria a que faltava; no dry-run devolve null e apenas anota — é
     * legítimo, porque nada que dependa do objeto muda de resposta por ele ainda não existir: unidade
     * que não está no banco não tem vínculo nem pessoa vinculada.
     *
     * @param array<string, ObjetoCobranca|null> $objetos        cache da execução (por referência)
     * @param list<string>                       $objetosCriados acumulador das unidades novas (por referência)
     */
    private function resolverObjeto(Carteira $carteira, CondominoImportavel $condomino, Tenant $tenant, ?User $usuario, array &$objetos, array &$objetosCriados): ?ObjetoCobranca
    {
        if (array_key_exists($condomino->unidade, $objetos)) {
            return $objetos[$condomino->unidade];
        }

        $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $condomino->unidade, $tenant);
        if ($objeto === null) {
            $objetosCriados[] = $condomino->unidade;

            if ($usuario !== null) {
                $input = new CriarObjetoInput();
                $input->carteiraId = (int) $carteira->getId();
                $input->identificacao = $condomino->unidade;
                $objeto = $this->criarObjeto->executar($input, $tenant, $usuario);
            }
        }

        return $objetos[$condomino->unidade] = $objeto;
    }

    /**
     * Acha a pessoa já cadastrada, em duas etapas (§5.6):
     *
     * 1. **Por documento**, no tenant — é a identidade forte.
     * 2. **Sem documento: por nome DENTRO do objeto**, entre quem já tem vínculo aberto ali. É a
     *    "decisão A" que o importador de inadimplência já usa. Sem esta etapa a pessoa sem CPF nasceria
     *    de novo a cada rodada mensal — o teste `testReimportacaoEhIdempotente` pegou exatamente isso.
     *
     * O escopo do nome é o OBJETO, nunca o tenant: dois "José da Silva" em unidades diferentes seguem
     * sendo duas pessoas. Casar por nome no tenant inteiro fundiria homônimos, falha já corrigida neste
     * domínio em `opcoesDoTenant`.
     */
    private function pessoaCadastrada(Tenant $tenant, CondominoImportavel $condomino, ?ObjetoCobranca $objeto = null): ?Pessoa
    {
        if ($condomino->documento() !== null) {
            return $this->pessoaRepository->buscarPossiveisDuplicadas($tenant, $condomino->cpf, $condomino->cnpj)[0] ?? null;
        }

        if ($objeto === null) {
            return null;
        }

        $procurado = NormalizadorNome::normalizar($condomino->nome);
        if ($procurado === null) {
            return null;
        }

        foreach ($this->vinculoRepository->pessoasVinculadasAoObjeto($objeto) as $pessoa) {
            // Só casa quem NÃO tem documento: se a cadastrada tem CPF e a do relatório não, são
            // pessoas diferentes até prova em contrário — o documento é o dado mais forte dos dois.
            if ($pessoa->getCpf() === null && $pessoa->getCnpj() === null
                && NormalizadorNome::normalizar($pessoa->getNome()) === $procurado) {
                return $pessoa;
            }
        }

        return null;
    }

    /**
     * A unidade já tem vínculo aberto com esta pessoa? Pergunta ao estado da execução primeiro (o que as
     * linhas anteriores fariam) e só então ao banco. Pessoa ou unidade que ainda não existem no banco não
     * podem ter vínculo — por isso o dry-run responde igual sem precisar de entidade.
     */
    private function temVinculoAberto(PessoaEmImportacao $estado, string $unidade, ?ObjetoCobranca $objeto): bool
    {
        if ($estado->temVinculoAberto($unidade)) {
            return true;
        }

        $pessoa = $estado->pessoa();
        if ($pessoa === null || $objeto === null) {
            return false;
        }

        foreach ($this->vinculoRepository->todosDoObjetoComPessoa($objeto) as $vinculo) {
            if ($vinculo->getPessoa()?->getId() === $pessoa->getId() && $vinculo->getDataFim() === null) {
                return true;
            }
        }

        return false;
    }

    private function acrescentarEndereco(Pessoa $pessoa, CondominoImportavel $condomino, Tenant $tenant, User $user): void
    {
        $input = new AdicionarEnderecoPessoaInput();
        $input->pessoaId = (int) $pessoa->getId();
        $input->logradouro = $condomino->endereco['logradouro'] ?? null;
        $input->numero = $condomino->endereco['numero'] ?? null;
        $input->complemento = $condomino->endereco['complemento'] ?? null;
        $input->bairro = $condomino->endereco['bairro'] ?? null;
        $input->cidade = $condomino->endereco['cidade'] ?? null;
        $input->uf = $condomino->endereco['uf'] ?? null;
        $input->cep = $condomino->endereco['cep'] ?? null;
        $this->adicionarEndereco->executar($input, $tenant, $user);
    }

    /** @param string|null $telefone o primeiro número que a pessoa ainda não tem — não a célula crua */
    private function inputPessoaVinculada(CondominoImportavel $condomino, ?string $telefone): CriarPessoaVinculadaInput
    {
        $input = new CriarPessoaVinculadaInput();
        $input->nome = $condomino->nome;
        $input->cpf = $condomino->cpf;
        $input->cnpj = $condomino->cnpj;
        $input->email = $condomino->email;
        $input->telefone = $telefone;
        $input->tipoVinculo = $condomino->tipoVinculo;

        if ($condomino->temEndereco()) {
            $input->enderecoLogradouro = $condomino->endereco['logradouro'] ?? null;
            $input->enderecoNumero = $condomino->endereco['numero'] ?? null;
            $input->enderecoComplemento = $condomino->endereco['complemento'] ?? null;
            $input->enderecoBairro = $condomino->endereco['bairro'] ?? null;
            $input->enderecoCidade = $condomino->endereco['cidade'] ?? null;
            $input->enderecoUf = $condomino->endereco['uf'] ?? null;
            $input->enderecoCep = $condomino->endereco['cep'] ?? null;
        }

        return $input;
    }

    private function inputVinculo(CondominoImportavel $condomino, Pessoa $pessoa, ObjetoCobranca $objeto): VincularPessoaAObjetoInput
    {
        $input = new VincularPessoaAObjetoInput();
        $input->pessoaId = (int) $pessoa->getId();
        $input->objetoId = (int) $objeto->getId();
        $input->tipoVinculo = $condomino->tipoVinculo;

        return $input;
    }

    private function resolverCarteira(int $carteiraId, Tenant $tenant): Carteira
    {
        $carteira = $this->carteiraRepository->findOneByIdDoTenant($carteiraId, $tenant);
        if ($carteira === null) {
            throw new CarteiraNaoEncontradaException($carteiraId);
        }

        return $carteira;
    }
}
