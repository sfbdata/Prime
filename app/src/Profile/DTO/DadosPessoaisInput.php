<?php

declare(strict_types=1);

namespace App\Profile\DTO;

use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Profile\Enum\VinculoColaborador;
use App\Profile\Service\ChavePix;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modal "Editar informações" do Meu Perfil (desenho "07 - Meu Perfil", VS 18.10.26, ED.info).
 * O vínculo só vale para quem pode alterá-lo (o UseCase decide); as datas do contrato e o CNPJ
 * valem conforme o vínculo escolhido. Spec: docs/specs/meu-perfil-pagina.md §2.
 */
final class DadosPessoaisInput
{
    #[Assert\NotBlank(message: 'Informe o nome completo.')]
    #[Assert\Length(max: 255)]
    public ?string $nomeCompleto = null;

    #[Assert\Length(max: 255, maxMessage: 'A frase do perfil pode ter até {{ limit }} caracteres.')]
    public ?string $status = null;

    #[Assert\Length(max: 20, maxMessage: 'Telefone inválido.')]
    #[Assert\Regex(pattern: '/^[\d\s()+\-.]*$/', message: 'Telefone inválido.')]
    public ?string $telefone = null;

    /** Desenho 07 (`pixRecomp`): onde a pessoa recebe recompensas de indicação; vazio vale. */
    #[Assert\Length(max: ChavePix::TAMANHO_MAXIMO, maxMessage: 'Informe uma chave PIX válida: CPF, CNPJ, e-mail, celular ou chave aleatória.')]
    #[Assert\Callback(callback: [self::class, 'validarChavePix'], groups: ['perfil'])]
    public ?string $chavePixRecompensa = null;

    #[Assert\Length(max: 14, maxMessage: 'CPF inválido.')]
    #[Assert\Callback(callback: [self::class, 'validarCpf'], groups: ['perfil'])]
    public ?string $cpf = null;

    public ?\DateTimeInterface $dataNascimento = null;

    /** Desenho 05 de 21/10 (filtros): declarado pela pessoa; nulo = não preenchido. */
    public ?Genero $genero = null;

    public ?Atuacao $atuacao = null;

    public ?VinculoColaborador $vinculo = null;

    #[Assert\Length(max: 20)]
    public ?string $ctps = null;

    #[Assert\Length(max: 20)]
    public ?string $serie = null;

    public ?\DateTimeInterface $dataAdmissao = null;

    public ?\DateTimeInterface $contratoInicio = null;

    #[Assert\Length(max: 18, maxMessage: 'CNPJ inválido.')]
    #[Assert\Callback(callback: [self::class, 'validarCnpj'], groups: ['perfil'])]
    public ?string $cnpj = null;

    /** Modal aberto com o que já está gravado. */
    public static function dePerfil(PerfilOutput $perfil): self
    {
        $input                 = new self();
        $input->nomeCompleto   = $perfil->nomeExibido;
        $input->status         = $perfil->status;
        $input->telefone       = $perfil->telefone;
        $input->chavePixRecompensa = $perfil->chavePixRecompensa;
        $input->cpf            = $perfil->cpf;
        $input->dataNascimento = $perfil->dataNascimento;
        // O legado `nao_informado` (D1 da spec genero-no-cadastro) abre como vazio: não é mais uma opção.
        $input->genero         = $perfil->genero?->preenchido() === true ? $perfil->genero : null;
        $input->atuacao        = $perfil->atuacao;
        $input->vinculo        = $perfil->vinculo;
        $input->ctps           = $perfil->ctps;
        $input->serie          = $perfil->serie;
        $input->dataAdmissao   = $perfil->dataAdmissao;
        $input->contratoInicio = $perfil->contratoInicio;
        $input->cnpj           = $perfil->cnpj;

        return $input;
    }

    public static function validarCpf(?string $cpf, \Symfony\Component\Validator\Context\ExecutionContextInterface $contexto): void
    {
        $digitos = preg_replace('/\D/', '', (string) $cpf) ?? '';
        if ($digitos !== '' && strlen($digitos) !== 11) {
            $contexto->buildViolation('O CPF precisa ter 11 números.')->addViolation();
        }
    }

    public static function validarChavePix(?string $chave, \Symfony\Component\Validator\Context\ExecutionContextInterface $contexto): void
    {
        if (trim((string) $chave) !== '' && !ChavePix::valida((string) $chave)) {
            $contexto->buildViolation('Informe uma chave PIX válida: CPF, CNPJ, e-mail, celular ou chave aleatória.')->addViolation();
        }
    }

    public static function validarCnpj(?string $cnpj, \Symfony\Component\Validator\Context\ExecutionContextInterface $contexto): void
    {
        $digitos = preg_replace('/\D/', '', (string) $cnpj) ?? '';
        if ($digitos !== '' && strlen($digitos) !== 14) {
            $contexto->buildViolation('O CNPJ precisa ter 14 números.')->addViolation();
        }
    }
}
