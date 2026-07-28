<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\EstadoCivil;
use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada da "Nova pessoa" DENTRO do objeto: cadastra a pessoa e já a vincula ao objeto. `objetoId`
 * NÃO é campo — vem da rota. Só o nome é obrigatório. A normalização dos textos ocorre no
 * CriarPessoaUseCase.
 *
 * 2026-07-28 (modal único, `docs/specs/cobranca-modal-unico-pessoa.md`): o cadastro passou a perguntar
 * o MESMO que a edição mostra, distribuído nas três abas do modal — qualificação completa, um endereço,
 * um telefone e um e-mail. Cada item de lista é opcional e, quando vem preenchido, nasce como o `atual`
 * da sua lista (regra do `Adicionar*PessoaUseCase`, que é quem grava).
 *
 * O endereço é opcional COMO BLOCO: ou vem vazio, ou vem com os obrigatórios todos. Meio endereço não
 * entra — ver `validarEnderecoCompleto`.
 */
final class CriarPessoaVinculadaInput
{
    #[Assert\NotBlank(message: 'Informe o nome da pessoa.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nome = null;

    #[Assert\Length(max: 14, maxMessage: 'O CPF pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cpf = null;

    #[Assert\Length(max: 18, maxMessage: 'O CNPJ pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cnpj = null;

    #[Assert\Email(message: 'E-mail inválido.')]
    #[Assert\Length(max: 255, maxMessage: 'O e-mail pode ter no máximo {{ limit }} caracteres.')]
    public ?string $email = null;

    #[Assert\Length(max: 20, maxMessage: 'O telefone pode ter no máximo {{ limit }} caracteres.')]
    public ?string $telefone = null;

    /**
     * WhatsApp ou fixo do telefone informado no cadastro. Mesmo default do `AdicionarTelefonePessoaInput`
     * (`Fixo`): quem não tocar no campo cadastra o que já cadastrava. Sem telefone, é ignorado.
     */
    public ?TipoTelefone $tipoTelefone = TipoTelefone::Fixo;

    public TipoVinculo $tipoVinculo = TipoVinculo::Outro;

    // ── Qualificação (os mesmos campos do `EditarQualificacaoPessoaInput`) ──────────────────────────
    public ?\DateTimeImmutable $dataNascimento = null;

    public ?EstadoCivil $estadoCivil = null;

    #[Assert\Length(max: 120, maxMessage: 'A profissão pode ter no máximo {{ limit }} caracteres.')]
    public ?string $profissao = null;

    #[Assert\Length(max: 20, maxMessage: 'O RG pode ter no máximo {{ limit }} caracteres.')]
    public ?string $rg = null;

    #[Assert\Length(max: 20, maxMessage: 'O órgão emissor pode ter no máximo {{ limit }} caracteres.')]
    public ?string $orgaoEmissorRg = null;

    public ?string $observacao = null;

    // ── Endereço (opcional como bloco; os limites são os do `AdicionarEnderecoPessoaInput`) ─────────
    #[Assert\Length(max: 255, maxMessage: 'O logradouro pode ter no máximo {{ limit }} caracteres.')]
    public ?string $enderecoLogradouro = null;

    #[Assert\Length(max: 20, maxMessage: 'O número pode ter no máximo {{ limit }} caracteres.')]
    public ?string $enderecoNumero = null;

    #[Assert\Length(max: 120, maxMessage: 'O complemento pode ter no máximo {{ limit }} caracteres.')]
    public ?string $enderecoComplemento = null;

    #[Assert\Length(max: 120, maxMessage: 'O bairro pode ter no máximo {{ limit }} caracteres.')]
    public ?string $enderecoBairro = null;

    #[Assert\Length(max: 120, maxMessage: 'A cidade pode ter no máximo {{ limit }} caracteres.')]
    public ?string $enderecoCidade = null;

    #[Assert\Length(max: 2, maxMessage: 'A UF deve ter {{ limit }} caracteres.')]
    public ?string $enderecoUf = null;

    #[Assert\Length(max: 9, maxMessage: 'O CEP pode ter no máximo {{ limit }} caracteres.')]
    public ?string $enderecoCep = null;

    /**
     * O endereço é tudo-ou-nada. Quem começou a preencher precisa terminar: gravar "Rua X" sem cidade
     * nem CEP produziria um endereço que não serve para carta, oficial de justiça nem consulta — e ele
     * ainda nasceria como o `atual` da pessoa, escondendo a falta atrás de um dado pela metade.
     *
     * O complemento não é exigido (é opcional na lista da ficha também), mas CONTA como "encostou no
     * bloco" — achado da revisão de 2026-07-28. Deixá-lo de fora fazia "Apto 302" sozinho ser jogado
     * fora em silêncio: o endereço não era criado e nada avisava. Contando, o gestor recebe a lista do
     * que falta, que é a resposta honesta para quem claramente quis endereçar.
     */
    #[Assert\Callback]
    public function validarEnderecoCompleto(ExecutionContextInterface $context): void
    {
        $obrigatorios = [
            'enderecoLogradouro' => 'Informe o logradouro.',
            'enderecoNumero' => 'Informe o número.',
            'enderecoBairro' => 'Informe o bairro.',
            'enderecoCidade' => 'Informe a cidade.',
            'enderecoUf' => 'Informe a UF.',
            'enderecoCep' => 'Informe o CEP.',
        ];

        if (!$this->temAlgumDadoDeEndereco()) {
            return;
        }

        foreach ($obrigatorios as $campo => $mensagem) {
            if (trim((string) $this->{$campo}) === '') {
                $context->buildViolation($mensagem)->atPath($campo)->addViolation();
            }
        }

        // A UF é `exactly: 2` na lista da ficha (`AdicionarEnderecoPessoaInput`). Aqui o atributo só
        // pode limitar o máximo — o campo é opcional e `exactly` recusaria o cadastro SEM endereço —,
        // então o piso vem junto do resto do bloco, para as duas telas gravarem a mesma coisa.
        $uf = trim((string) $this->enderecoUf);
        if ($uf !== '' && mb_strlen($uf) !== 2) {
            $context->buildViolation('A UF deve ter 2 caracteres.')->atPath('enderecoUf')->addViolation();
        }
    }

    /**
     * Se o gestor encostou no bloco de endereço. Serve à validação acima e ao UseCase, que só chama o
     * `AdicionarEnderecoPessoaUseCase` quando há endereço de verdade para gravar — fonte única para as
     * duas decisões, que precisam concordar.
     */
    public function temAlgumDadoDeEndereco(): bool
    {
        foreach (['enderecoLogradouro', 'enderecoNumero', 'enderecoComplemento', 'enderecoBairro', 'enderecoCidade', 'enderecoUf', 'enderecoCep'] as $campo) {
            if (trim((string) $this->{$campo}) !== '') {
                return true;
            }
        }

        return false;
    }
}
