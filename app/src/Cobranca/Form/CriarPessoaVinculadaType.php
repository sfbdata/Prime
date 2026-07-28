<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\Enum\EstadoCivil;
use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Nova pessoa" dentro do objeto: cadastra a pessoa e a vincula ao objeto num passo só. `objetoId` vem
 * da rota. Só o nome é obrigatório; os demais dados são opcionais. Validação nos #[Assert] do DTO.
 *
 * 2026-07-28 (modal único, `docs/specs/cobranca-modal-unico-pessoa.md`): os campos foram distribuídos
 * nas MESMAS três abas que a edição mostra — Qualificação, Endereços, Telefones e E-mails. Os rótulos e
 * os limites repetem os dos Types da ficha (`EditarQualificacaoPessoaType`, `AdicionarEnderecoType`,
 * `AdicionarTelefoneType`, `AdicionarEmailType`) de propósito: o gestor tem de ver o mesmo formulário
 * nos dois lados, e um rótulo divergente aqui seria a primeira coisa a denunciar que são dois.
 */
final class CriarPessoaVinculadaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // ── Aba Qualificação ───────────────────────────────────────────────────────────────────────
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('tipoVinculo', EnumType::class, [
                'label' => 'Tipo de vínculo',
                'class' => TipoVinculo::class,
                'choice_label' => static fn (TipoVinculo $t): string => $t->label(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('cpf', TextType::class, [
                'label' => 'CPF',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 14],
            ])
            ->add('cnpj', TextType::class, [
                'label' => 'CNPJ',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 18],
            ])
            ->add('dataNascimento', DateType::class, [
                'label' => 'Data de nascimento',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('estadoCivil', EnumType::class, [
                'label' => 'Estado civil',
                'class' => EstadoCivil::class,
                'choice_label' => static fn (EstadoCivil $e): string => $e->rotulo(),
                'required' => false,
                'placeholder' => 'Não informado',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('profissao', TextType::class, [
                'label' => 'Profissão',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
            ])
            ->add('rg', TextType::class, [
                'label' => 'RG',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 20],
            ])
            ->add('orgaoEmissorRg', TextType::class, [
                'label' => 'Órgão emissor',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 20, 'placeholder' => 'Ex.: SSP/CE'],
            ])
            ->add('observacao', TextareaType::class, [
                'label' => 'Observação',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ]);

        // ── Aba Endereços (um endereço; opcional como bloco — ver `validarEnderecoCompleto` no DTO) ──
        $builder
            ->add('enderecoLogradouro', TextType::class, [
                'label' => 'Logradouro',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('enderecoNumero', TextType::class, [
                'label' => 'Número',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 20],
            ])
            ->add('enderecoComplemento', TextType::class, [
                'label' => 'Complemento',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
            ])
            ->add('enderecoBairro', TextType::class, [
                'label' => 'Bairro',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
            ])
            ->add('enderecoCidade', TextType::class, [
                'label' => 'Cidade',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
            ])
            ->add('enderecoUf', TextType::class, [
                'label' => 'UF',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 2],
            ])
            ->add('enderecoCep', TextType::class, [
                'label' => 'CEP',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 9],
            ]);

        // ── Aba Telefones e E-mails (um de cada) ───────────────────────────────────────────────────
        $builder
            ->add('telefone', TextType::class, [
                'label' => 'Telefone',
                'required' => false,
                // Mesmos `attr` do `AdicionarTelefoneType`: `data-mascara` é o gancho do formatador de
                // digitação (`telefone-mascara.js`), que age por delegação no documento.
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 20,
                    'inputmode' => 'tel',
                    'data-mascara' => 'telefone',
                    'placeholder' => '(00) 00000-0000',
                ],
            ])
            ->add('tipoTelefone', EnumType::class, [
                'class' => TipoTelefone::class,
                'label' => 'Tipo',
                'expanded' => true,
                'multiple' => false,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (TipoTelefone $tipo): string => $tipo->label(),
                'choice_attr' => static fn (TipoTelefone $tipo): array => ['data-tipo' => $tipo->value],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CriarPessoaVinculadaInput::class,
        ]);
    }
}
