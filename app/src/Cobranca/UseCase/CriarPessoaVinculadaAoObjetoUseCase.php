<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AdicionarEmailPessoaInput;
use App\Cobranca\DTO\AdicionarEnderecoPessoaInput;
use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Nova pessoa" dentro do objeto (aba Responsáveis): cadastra a pessoa e já a vincula ao objeto.
 *
 * História: trabalhando a cobrança de um objeto, o gestor adiciona um novo envolvido (devedor, avalista,
 * fiador...). Numa passada, o sistema cadastra a Pessoa — com a qualificação que ele souber na hora e,
 * se ele informou, o primeiro endereço, telefone e e-mail — e cria o vínculo pessoa↔objeto, que a faz
 * aparecer na aba. O objeto é resolvido por id + tenant DENTRO do VincularPessoaAObjetoUseCase (guarda
 * multi-tenant); objeto inexistente/de outro escritório interrompe com exceção de domínio. NÃO define a
 * pessoa cobrada — isso é a ação separada de "trocar cobrada".
 *
 * 2026-07-28 (modal único, `docs/specs/cobranca-modal-unico-pessoa.md`): telefone e e-mail deixaram de
 * ir pelo bridge `Pessoa::setTelefone()`/`setEmail()` do `CriarPessoaUseCase` e passaram pelos
 * `Adicionar*PessoaUseCase` — os MESMOS que a edição usa. Motivo: o bridge não sabe o TIPO do telefone
 * (WhatsApp × fixo) nem registra quem cadastrou. O resultado na tela é o mesmo (um item na lista, já
 * `atual`, com a coluna-sombra sincronizada), porque é o que aqueles UseCases fazem com o primeiro item
 * de cada lista. Passar o valor NOS DOIS caminhos criaria dois registros do mesmo número — por isso o
 * `CriarPessoaInput` daqui vai sem telefone e sem e-mail.
 *
 * TUDO NUMA TRANSAÇÃO (achado da revisão de 2026-07-28). O cadastro virou cinco gravações — pessoa,
 * endereço, telefone, e-mail e vínculo —, cada UseCase com o seu flush. Sem a transação, falhar no
 * vínculo (o último) deixaria no banco uma pessoa completa que NÃO aparece em lugar nenhum da tela:
 * o que a liga a esta cobrança é justamente o vínculo. O gestor veria só o flash de erro e cadastraria
 * de novo, criando a segunda. Antes desta frente o resíduo possível era uma linha; agora seriam quatro.
 */
final class CriarPessoaVinculadaAoObjetoUseCase
{
    public function __construct(
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly VincularPessoaAObjetoUseCase $vincularPessoa,
        private readonly AdicionarEnderecoPessoaUseCase $adicionarEndereco,
        private readonly AdicionarTelefonePessoaUseCase $adicionarTelefone,
        private readonly AdicionarEmailPessoaUseCase $adicionarEmail,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(CriarPessoaVinculadaInput $input, int $objetoId, Tenant $tenant, User $criadoPor): VinculoPessoaObjeto
    {
        return $this->em->wrapInTransaction(
            fn (): VinculoPessoaObjeto => $this->cadastrarEVincular($input, $objetoId, $tenant, $criadoPor),
        );
    }

    private function cadastrarEVincular(CriarPessoaVinculadaInput $input, int $objetoId, Tenant $tenant, User $criadoPor): VinculoPessoaObjeto
    {
        $pessoaInput = new CriarPessoaInput();
        $pessoaInput->nome = $input->nome;
        $pessoaInput->cpf = $input->cpf;
        $pessoaInput->cnpj = $input->cnpj;
        $pessoaInput->observacao = $input->observacao;
        $pessoaInput->dataNascimento = $input->dataNascimento;
        $pessoaInput->estadoCivil = $input->estadoCivil;
        $pessoaInput->profissao = $input->profissao;
        $pessoaInput->rg = $input->rg;
        $pessoaInput->orgaoEmissorRg = $input->orgaoEmissorRg;
        $pessoa = $this->criarPessoa->executar($pessoaInput, $tenant, $criadoPor);

        $pessoaId = (int) $pessoa->getId();

        if ($input->temAlgumDadoDeEndereco()) {
            $enderecoInput = new AdicionarEnderecoPessoaInput();
            $enderecoInput->pessoaId = $pessoaId;
            $enderecoInput->logradouro = $input->enderecoLogradouro;
            $enderecoInput->numero = $input->enderecoNumero;
            $enderecoInput->complemento = $input->enderecoComplemento;
            $enderecoInput->bairro = $input->enderecoBairro;
            $enderecoInput->cidade = $input->enderecoCidade;
            $enderecoInput->uf = $input->enderecoUf;
            $enderecoInput->cep = $input->enderecoCep;
            $this->adicionarEndereco->executar($enderecoInput, $tenant, $criadoPor);
        }

        if (trim((string) $input->telefone) !== '') {
            $telefoneInput = new AdicionarTelefonePessoaInput();
            $telefoneInput->pessoaId = $pessoaId;
            $telefoneInput->numero = $input->telefone;
            $telefoneInput->tipo = $input->tipoTelefone;
            $this->adicionarTelefone->executar($telefoneInput, $tenant, $criadoPor);
        }

        if (trim((string) $input->email) !== '') {
            $emailInput = new AdicionarEmailPessoaInput();
            $emailInput->pessoaId = $pessoaId;
            $emailInput->email = $input->email;
            $this->adicionarEmail->executar($emailInput, $tenant, $criadoPor);
        }

        $vinculoInput = new VincularPessoaAObjetoInput();
        $vinculoInput->objetoId = $objetoId;
        $vinculoInput->pessoaId = $pessoaId;
        $vinculoInput->tipoVinculo = $input->tipoVinculo;

        return $this->vincularPessoa->executar($vinculoInput, $tenant, $criadoPor);
    }
}
