<?php

declare(strict_types=1);

namespace App\Cobranca\Form;

use App\Cobranca\DTO\EditarQualificacaoPessoaInput;
use App\Cobranca\Enum\EstadoCivil;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Edição da qualificação da Pessoa (spec de qualificação §3/§7): SOMENTE os campos únicos —
 * `email`/`telefone` NUNCA aparecem aqui, são geridos pelas listas (SPEC §6). `pessoaId` é campo
 * oculto preenchido pelo controller a partir da rota.
 */
final class EditarQualificacaoPessoaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nome', TextType::class, [
                'label' => 'Nome',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('cpf', TextType::class, [
                'label' => 'CPF',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('cnpj', TextType::class, [
                'label' => 'CNPJ',
                'required' => false,
                'attr' => ['class' => 'form-control'],
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
                'attr' => ['class' => 'form-control'],
            ])
            ->add('rg', TextType::class, [
                'label' => 'RG',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('orgaoEmissorRg', TextType::class, [
                'label' => 'Órgão emissor',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Ex.: SSP/CE'],
            ])
            ->add('observacao', TextareaType::class, [
                'label' => 'Observação',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditarQualificacaoPessoaInput::class,
        ]);
    }
}
