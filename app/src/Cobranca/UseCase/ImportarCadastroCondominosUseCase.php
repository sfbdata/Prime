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
     * A projeção acompanha o que JÁ foi visto no próprio arquivo (unidades e documentos), senão duas
     * linhas da mesma unidade contariam dois objetos novos — número que faria o operador desconfiar do
     * resumo com razão.
     */
    public function prever(int $carteiraId, ResultadoLeituraCadastro $leitura, Tenant $tenant): ResultadoImportacaoCadastro
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        $objetosNovos = [];
        $pessoasNovas = [];
        $vinculosNovos = [];
        $reaproveitadas = 0;
        $telefones = 0;
        $emails = 0;
        $enderecos = 0;
        $documentosVistos = [];

        foreach ($leitura->importaveis as $condomino) {
            $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $condomino->unidade, $tenant);
            if ($objeto === null && !isset($objetosNovos[$condomino->unidade])) {
                $objetosNovos[$condomino->unidade] = true;
            }

            $documento = $condomino->documento();
            $pessoa = $this->pessoaPorDocumento($tenant, $condomino, $objeto);
            $jaVistaNoArquivo = $documento !== null && isset($documentosVistos[$documento]);

            if ($pessoa === null && !$jaVistaNoArquivo) {
                $pessoasNovas[] = $condomino->nome;
                $vinculosNovos[] = $condomino->unidade . ' → ' . $condomino->nome;
                $telefones += count($condomino->telefones);
                $emails += $condomino->email !== null ? 1 : 0;
                $enderecos += $condomino->temEndereco() ? 1 : 0;
            } else {
                ++$reaproveitadas;
                if ($pessoa !== null) {
                    if ($objeto !== null && !$this->temVinculoAberto($pessoa, $objeto)) {
                        $vinculosNovos[] = $condomino->unidade . ' → ' . $condomino->nome;
                    }
                    $telefones += count($this->telefonesAusentes($pessoa, $condomino));
                    $emails += $this->emailAusente($pessoa, $condomino) ? 1 : 0;
                    $enderecos += $this->enderecoAusente($pessoa, $condomino) ? 1 : 0;
                }
            }

            if ($documento !== null) {
                $documentosVistos[$documento] = true;
            }
        }

        return new ResultadoImportacaoCadastro(
            array_keys($objetosNovos),
            $pessoasNovas,
            $vinculosNovos,
            $telefones,
            $emails,
            $enderecos,
            $reaproveitadas,
            $leitura->rejeitadas,
            $leitura->linhasIgnoradas,
        );
    }

    /** Persiste numa transação única (ou tudo, ou nada). */
    public function confirmar(int $carteiraId, ResultadoLeituraCadastro $leitura, Tenant $tenant, User $user): ResultadoImportacaoCadastro
    {
        $carteira = $this->resolverCarteira($carteiraId, $tenant);

        return $this->em->wrapInTransaction(function () use ($carteira, $leitura, $tenant, $user): ResultadoImportacaoCadastro {
            $objetosCriados = [];
            $pessoasCriadas = [];
            $vinculosCriados = [];
            $reaproveitadas = 0;
            $telefones = 0;
            $emails = 0;
            $enderecos = 0;

            foreach ($leitura->importaveis as $condomino) {
                $objeto = $this->resolverObjeto($carteira, $condomino, $tenant, $user, $objetosCriados);
                $pessoa = $this->pessoaPorDocumento($tenant, $condomino, $objeto);

                if ($pessoa === null) {
                    // Nasce já vinculada, com contato e endereço — um único UseCase, as mesmas regras da tela.
                    $vinculo = $this->criarPessoaVinculada->executar(
                        $this->inputPessoaVinculada($condomino),
                        (int) $objeto->getId(),
                        $tenant,
                        $user,
                    );
                    $pessoa = $vinculo->getPessoa();
                    $pessoasCriadas[] = $condomino->nome;
                    $vinculosCriados[] = $condomino->unidade . ' → ' . $condomino->nome;
                    $telefones += count($condomino->telefones) > 0 ? 1 : 0;
                    $emails += $condomino->email !== null ? 1 : 0;
                    $enderecos += $condomino->temEndereco() ? 1 : 0;

                    // O input leva UM telefone (o primeiro); os demais entram na lista logo abaixo.
                    $telefones += $this->acrescentarTelefones($pessoa, $condomino, $tenant, $user);

                    continue;
                }

                ++$reaproveitadas;
                if (!$this->temVinculoAberto($pessoa, $objeto)) {
                    $this->vincular->executar($this->inputVinculo($condomino, $pessoa, $objeto), $tenant, $user);
                    $vinculosCriados[] = $condomino->unidade . ' → ' . $condomino->nome;
                }

                $telefones += $this->acrescentarTelefones($pessoa, $condomino, $tenant, $user);
                $emails += $this->acrescentarEmail($pessoa, $condomino, $tenant, $user);
                $enderecos += $this->acrescentarEndereco($pessoa, $condomino, $tenant, $user);
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
        });
    }

    /** @param list<string> $objetosCriados acumulador (por referência) das unidades criadas nesta execução */
    private function resolverObjeto(Carteira $carteira, CondominoImportavel $condomino, Tenant $tenant, User $user, array &$objetosCriados): ObjetoCobranca
    {
        $objeto = $this->objetoRepository->findOnePorIdentificacaoNaCarteira($carteira, $condomino->unidade, $tenant);
        if ($objeto !== null) {
            return $objeto;
        }

        $input = new CriarObjetoInput();
        $input->carteiraId = (int) $carteira->getId();
        $input->identificacao = $condomino->unidade;
        $objeto = $this->criarObjeto->executar($input, $tenant, $user);
        $objetosCriados[] = $condomino->unidade;

        return $objeto;
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
    private function pessoaPorDocumento(Tenant $tenant, CondominoImportavel $condomino, ?ObjetoCobranca $objeto = null): ?Pessoa
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

    private function temVinculoAberto(Pessoa $pessoa, ObjetoCobranca $objeto): bool
    {
        foreach ($this->vinculoRepository->todosDoObjetoComPessoa($objeto) as $vinculo) {
            if ($vinculo->getPessoa()?->getId() === $pessoa->getId() && $vinculo->getDataFim() === null) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> telefones do relatório que a pessoa ainda NÃO tem (comparação por dígitos) */
    private function telefonesAusentes(Pessoa $pessoa, CondominoImportavel $condomino): array
    {
        $existentes = [];
        foreach ($pessoa->getTelefones() as $telefone) {
            $existentes[] = preg_replace('/\D/', '', (string) $telefone->getNumero());
        }

        $ausentes = [];
        foreach ($condomino->telefones as $numero) {
            $digitos = preg_replace('/\D/', '', $numero);
            if ($digitos !== '' && !in_array($digitos, $existentes, true)) {
                $ausentes[] = $numero;
                $existentes[] = $digitos; // o próprio relatório pode repetir o número
            }
        }

        return $ausentes;
    }

    private function acrescentarTelefones(Pessoa $pessoa, CondominoImportavel $condomino, Tenant $tenant, User $user): int
    {
        $acrescentados = 0;
        foreach ($this->telefonesAusentes($pessoa, $condomino) as $numero) {
            $input = new AdicionarTelefonePessoaInput();
            $input->pessoaId = (int) $pessoa->getId();
            $input->numero = $numero;
            $this->adicionarTelefone->executar($input, $tenant, $user);
            ++$acrescentados;
        }

        return $acrescentados;
    }

    private function emailAusente(Pessoa $pessoa, CondominoImportavel $condomino): bool
    {
        if ($condomino->email === null) {
            return false;
        }

        foreach ($pessoa->getEmails() as $email) {
            if (mb_strtolower((string) $email->getEmail()) === mb_strtolower($condomino->email)) {
                return false;
            }
        }

        return true;
    }

    private function acrescentarEmail(Pessoa $pessoa, CondominoImportavel $condomino, Tenant $tenant, User $user): int
    {
        if (!$this->emailAusente($pessoa, $condomino)) {
            return 0;
        }

        $input = new AdicionarEmailPessoaInput();
        $input->pessoaId = (int) $pessoa->getId();
        $input->email = $condomino->email;
        $this->adicionarEmail->executar($input, $tenant, $user);

        return 1;
    }

    private function enderecoAusente(Pessoa $pessoa, CondominoImportavel $condomino): bool
    {
        if (!$condomino->temEndereco()) {
            return false;
        }

        $novo = $this->chaveEndereco($condomino->endereco);
        foreach ($pessoa->getEnderecos() as $endereco) {
            $atual = $this->chaveEndereco([
                'logradouro' => (string) $endereco->getLogradouro(),
                'numero' => (string) $endereco->getNumero(),
                'complemento' => (string) $endereco->getComplemento(),
                'cep' => (string) $endereco->getCep(),
            ]);
            if ($atual === $novo) {
                return false;
            }
        }

        return true;
    }

    private function acrescentarEndereco(Pessoa $pessoa, CondominoImportavel $condomino, Tenant $tenant, User $user): int
    {
        if (!$this->enderecoAusente($pessoa, $condomino)) {
            return 0;
        }

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

        return 1;
    }

    /** @param array<string, string> $endereco */
    private function chaveEndereco(array $endereco): string
    {
        $partes = [
            $endereco['logradouro'] ?? '',
            $endereco['numero'] ?? '',
            $endereco['complemento'] ?? '',
            preg_replace('/\D/', '', $endereco['cep'] ?? '') ?? '',
        ];

        return mb_strtolower(trim(implode('|', array_map('trim', $partes))));
    }

    private function inputPessoaVinculada(CondominoImportavel $condomino): CriarPessoaVinculadaInput
    {
        $input = new CriarPessoaVinculadaInput();
        $input->nome = $condomino->nome;
        $input->cpf = $condomino->cpf;
        $input->cnpj = $condomino->cnpj;
        $input->email = $condomino->email;
        $input->telefone = $condomino->telefones[0] ?? null;
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
